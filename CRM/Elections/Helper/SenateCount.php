<?php

/**
 * Single transferable vote count following the Australian Senate rules
 * (Commonwealth Electoral Act 1918, s273): Droop quota, weighted inclusive
 * Gregory surplus transfers and exclusion of one candidate at a time.
 *
 * Transfer values are kept as exact fractions. As in the AEC count, the votes
 * credited to a candidate by each transfer are truncated to a whole number.
 */
class CRM_Elections_Helper_SenateCount {

  private $seats;
  private $candidateIds;
  private $ballots = [];

  private $quota;
  private $votes = [];
  private $status = [];
  private $parcels = [];
  private $history = [];
  private $elected = [];
  private $excluded = [];
  private $pendingSurpluses = [];
  private $tieByLot = FALSE;

  /**
   * @param int $seats
   * @param array $candidateIds
   * @param array $ballots
   *   One array per voter mapping preference rank to candidate ID. Preferences
   *   after the first break in the rank sequence are ignored.
   */
  public function __construct($seats, $candidateIds, $ballots) {
    $this->seats = (int) $seats;
    $this->candidateIds = array_values($candidateIds);

    foreach ($ballots as $ballot) {
      $ballot = $this->normaliseBallot($ballot);
      if (!empty($ballot)) {
        $this->ballots[] = $ballot;
      }
    }
  }

  /**
   * Run the count.
   *
   * @return array
   *   'elected' and 'continuing' candidate IDs in order, 'excluded' candidate
   *   IDs in order of exclusion, the 'quota', and 'tie_by_lot' which is TRUE
   *   if a tie deciding the result had to be broken by random draw.
   */
  public function run() {
    $this->quota = intdiv(count($this->ballots), $this->seats + 1) + 1;

    foreach ($this->candidateIds as $candidateId) {
      $this->votes[$candidateId] = 0;
      $this->status[$candidateId] = 'continuing';
      $this->parcels[$candidateId] = [];
    }

    $this->distributeFirstPreferences();

    while (count($this->elected) < $this->seats) {
      $continuing = $this->getContinuing();
      $vacancies = $this->seats - count($this->elected);

      if (count($continuing) <= $vacancies) {
        foreach ($this->orderByVotes($continuing) as $candidateId) {
          $this->elect($candidateId);
        }
        break;
      }

      if (!empty($this->pendingSurpluses)) {
        $this->distributeSurplus($this->takeLargestSurplus());
        continue;
      }

      if (count($continuing) == 2 && $vacancies == 1) {
        $this->elect($this->breakTie($this->withMostVotes($continuing), FALSE, TRUE));
        break;
      }

      $this->exclude($this->breakTie($this->withFewestVotes($continuing), TRUE, TRUE));
    }

    return [
      'elected' => $this->elected,
      'continuing' => $this->orderByVotes($this->getContinuing()),
      'excluded' => $this->excluded,
      'quota' => $this->quota,
      'tie_by_lot' => $this->tieByLot,
    ];
  }

  private function normaliseBallot($ballot) {
    ksort($ballot);
    $preferences = [];
    $expectedRank = 1;
    foreach ($ballot as $rank => $candidateId) {
      if ($rank != $expectedRank || !in_array($candidateId, $this->candidateIds)) {
        break;
      }
      $preferences[] = $candidateId;
      $expectedRank++;
    }
    return $preferences;
  }

  private function distributeFirstPreferences() {
    $firstPreferences = [];
    foreach ($this->ballots as $index => $ballot) {
      $firstPreferences[$ballot[0]][] = $index;
    }
    foreach ($firstPreferences as $candidateId => $ballotIndexes) {
      $this->parcels[$candidateId][] = [
        'ballots' => $ballotIndexes,
        'value' => [1, 1],
      ];
      $this->votes[$candidateId] = count($ballotIndexes);
    }
    $this->endCount();
  }

  /**
   * Transfer every ballot held by an elected candidate at a reduced value so
   * that the candidate keeps exactly a quota.
   */
  private function distributeSurplus($candidateId) {
    $surplus = $this->votes[$candidateId] - $this->quota;
    $total = $this->votes[$candidateId];
    $parcels = $this->parcels[$candidateId];
    $this->parcels[$candidateId] = [];
    $this->votes[$candidateId] = $this->quota;

    if ($surplus > 0) {
      $reduced = [];
      foreach ($parcels as $parcel) {
        $reduced[] = [
          'ballots' => $parcel['ballots'],
          'value' => $this->multiply($parcel['value'], [$surplus, $total]),
        ];
      }
      $this->transfer($reduced);
    }

    $this->endCount();
  }

  /**
   * Exclude a candidate, transferring their ballots one transfer value at a
   * time, highest first. Each transfer is a separate count.
   */
  private function exclude($candidateId) {
    $this->status[$candidateId] = 'excluded';
    $this->excluded[] = $candidateId;

    $groups = [];
    foreach ($this->parcels[$candidateId] as $parcel) {
      $key = implode('/', $parcel['value']);
      if (!isset($groups[$key])) {
        $groups[$key] = ['ballots' => [], 'value' => $parcel['value']];
      }
      $groups[$key]['ballots'] = array_merge($groups[$key]['ballots'], $parcel['ballots']);
    }
    usort($groups, function ($groupA, $groupB) {
      return $this->compare($groupB['value'], $groupA['value']);
    });
    $this->parcels[$candidateId] = [];

    foreach ($groups as $group) {
      $this->votes[$candidateId] -= $this->floor($this->multiply([count($group['ballots']), 1], $group['value']));
      $this->transfer([$group]);
      $this->endCount();
      if (count($this->elected) >= $this->seats) {
        break;
      }
    }
    $this->votes[$candidateId] = 0;
  }

  /**
   * Pass ballots to their next continuing preference, crediting each
   * receiving candidate with the truncated value of the ballots received.
   */
  private function transfer($parcels) {
    $received = [];
    foreach ($parcels as $parcel) {
      $byCandidate = [];
      foreach ($parcel['ballots'] as $ballotIndex) {
        $nextCandidateId = $this->getNextContinuing($this->ballots[$ballotIndex]);
        if ($nextCandidateId !== NULL) {
          $byCandidate[$nextCandidateId][] = $ballotIndex;
        }
      }
      foreach ($byCandidate as $candidateId => $ballotIndexes) {
        $this->parcels[$candidateId][] = [
          'ballots' => $ballotIndexes,
          'value' => $parcel['value'],
        ];
        $value = $this->multiply([count($ballotIndexes), 1], $parcel['value']);
        $received[$candidateId] = isset($received[$candidateId]) ? $this->add($received[$candidateId], $value) : $value;
      }
    }

    foreach ($received as $candidateId => $value) {
      $this->votes[$candidateId] += $this->floor($value);
    }
  }

  private function getNextContinuing($ballot) {
    foreach ($ballot as $candidateId) {
      if ($this->status[$candidateId] == 'continuing') {
        return $candidateId;
      }
    }
    return NULL;
  }

  /**
   * Record the tally for tie breaking and elect anyone who reached a quota.
   */
  private function endCount() {
    $this->history[] = $this->votes;

    $reachedQuota = array_filter($this->getContinuing(), function ($candidateId) {
      return $this->votes[$candidateId] >= $this->quota;
    });
    foreach ($this->orderByVotes($reachedQuota) as $candidateId) {
      if (count($this->elected) < $this->seats) {
        $this->elect($candidateId);
        $this->pendingSurpluses[] = $candidateId;
      }
    }
  }

  private function elect($candidateId) {
    $this->status[$candidateId] = 'elected';
    $this->elected[] = $candidateId;
  }

  private function takeLargestSurplus() {
    $candidateId = $this->breakTie($this->withMostVotes($this->pendingSurpluses), FALSE, FALSE);
    $this->pendingSurpluses = array_values(array_diff($this->pendingSurpluses, [$candidateId]));
    return $candidateId;
  }

  private function getContinuing() {
    return array_values(array_filter($this->candidateIds, function ($candidateId) {
      return $this->status[$candidateId] == 'continuing';
    }));
  }

  private function withMostVotes($candidateIds) {
    $most = max(array_intersect_key($this->votes, array_flip($candidateIds)));
    return array_values(array_filter($candidateIds, function ($candidateId) use ($most) {
      return $this->votes[$candidateId] == $most;
    }));
  }

  private function withFewestVotes($candidateIds) {
    $fewest = min(array_intersect_key($this->votes, array_flip($candidateIds)));
    return array_values(array_filter($candidateIds, function ($candidateId) use ($fewest) {
      return $this->votes[$candidateId] == $fewest;
    }));
  }

  /**
   * Order candidates by votes, breaking ties as for any other decision. The
   * order does not change who is elected, so a random draw is not reported.
   */
  private function orderByVotes($candidateIds) {
    $ordered = [];
    while (!empty($candidateIds)) {
      $candidateId = $this->breakTie($this->withMostVotes($candidateIds), FALSE, FALSE);
      $ordered[] = $candidateId;
      $candidateIds = array_values(array_diff($candidateIds, [$candidateId]));
    }
    return $ordered;
  }

  /**
   * Choose between tied candidates using the most recent count at which their
   * votes differed, falling back to a random draw.
   *
   * @param array $candidateIds
   * @param bool $fewest
   *   TRUE to choose the candidate with the fewest votes, FALSE for the most.
   * @param bool $isDecisive
   *   TRUE if the choice can change who is elected.
   */
  private function breakTie($candidateIds, $fewest, $isDecisive) {
    for ($count = count($this->history) - 1; $count >= 0 && count($candidateIds) > 1; $count--) {
      $votes = array_intersect_key($this->history[$count], array_flip($candidateIds));
      $target = $fewest ? min($votes) : max($votes);
      $candidateIds = array_keys($votes, $target);
    }

    if (count($candidateIds) > 1) {
      if ($isDecisive) {
        $this->tieByLot = TRUE;
      }
      return $candidateIds[random_int(0, count($candidateIds) - 1)];
    }
    return reset($candidateIds);
  }

  private function multiply($a, $b) {
    return $this->reduce([$a[0] * $b[0], $a[1] * $b[1]]);
  }

  private function add($a, $b) {
    return $this->reduce([$a[0] * $b[1] + $b[0] * $a[1], $a[1] * $b[1]]);
  }

  private function compare($a, $b) {
    return $a[0] * $b[1] <=> $b[0] * $a[1];
  }

  private function floor($a) {
    return intdiv($a[0], $a[1]);
  }

  private function reduce($a) {
    $x = $a[0];
    $y = $a[1];
    while ($y != 0) {
      [$x, $y] = [$y, $x % $y];
    }
    return $x == 0 ? [0, 1] : [intdiv($a[0], $x), intdiv($a[1], $x)];
  }

}
