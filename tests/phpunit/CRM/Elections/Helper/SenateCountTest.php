<?php

use PHPUnit\Framework\TestCase;

/**
 * Hand-counted scenarios for the Senate (STV) count.
 */
class CRM_Elections_Helper_SenateCountTest extends TestCase {

  const A = 1;
  const B = 2;
  const C = 3;
  const D = 4;
  const E = 5;

  /**
   * A minority with a quota of first preferences wins a seat.
   */
  public function testMinorityWinsSeat() {
    $ballots = array_merge(
      $this->ballots(6, [self::A, self::B, self::C, self::D]),
      $this->ballots(3, [self::C]),
      $this->ballots(1, [self::D, self::C])
    );
    $result = $this->runCount(2, [self::A, self::B, self::C, self::D], $ballots);

    $this->assertEquals(4, $result['quota']);
    $this->assertEquals([self::A, self::C], $result['elected']);
    $this->assertEquals([self::D], $result['excluded']);
    $this->assertEquals([self::B], $result['continuing']);
    $this->assertFalse($result['tie_by_lot']);
  }

  /**
   * Surplus ballots move at a reduced transfer value, and the votes each
   * candidate receives are truncated. B, C and D then tie on 2 votes, and B
   * is excluded for having the fewest at the first count.
   */
  public function testSurplusTransferIsTruncated() {
    $ballots = array_merge(
      $this->ballots(5, [self::A, self::B]),
      $this->ballots(2, [self::A, self::C]),
      $this->ballots(2, [self::C]),
      $this->ballots(2, [self::D]),
      $this->ballots(1, [self::E])
    );
    $result = $this->runCount(3, [self::A, self::B, self::C, self::D, self::E], $ballots);

    $this->assertEquals(4, $result['quota']);
    $this->assertEquals(self::A, $result['elected'][0]);
    $this->assertEqualsCanonicalizing([self::A, self::C, self::D], $result['elected']);
    $this->assertEquals([self::E, self::B], $result['excluded']);
    $this->assertFalse($result['tie_by_lot'], 'Ordering tied winners is not a decisive draw.');
  }

  /**
   * The largest surplus is distributed first, so C is elected before D.
   */
  public function testLargestSurplusIsDistributedFirst() {
    $ballots = array_merge(
      $this->ballots(11, [self::A, self::C]),
      $this->ballots(9, [self::B, self::D]),
      $this->ballots(1, [self::D])
    );
    $result = $this->runCount(4, [self::A, self::B, self::C, self::D, self::E], $ballots);

    $this->assertEquals(5, $result['quota']);
    $this->assertEquals([self::A, self::B, self::C, self::D], $result['elected']);
    $this->assertEquals([self::E], $result['continuing']);
  }

  /**
   * An excluded candidate's ballots move one transfer value at a time. C is
   * elected by the first transfer, so the second one skips C and goes to D.
   */
  public function testExclusionTransfersEachValueSeparately() {
    $ballots = array_merge(
      $this->ballots(9, [self::A, self::B, self::C, self::D]),
      $this->ballots(2, [self::B, self::C]),
      $this->ballots(5, [self::C]),
      $this->ballots(4, [self::D]),
      $this->ballots(4, [self::E])
    );
    $result = $this->runCount(3, [self::A, self::B, self::C, self::D, self::E], $ballots);

    $this->assertEquals(7, $result['quota']);
    $this->assertEquals([self::A, self::C, self::D], $result['elected']);
    $this->assertEquals([self::B], $result['excluded']);
    $this->assertEquals([self::E], $result['continuing']);
  }

  /**
   * Preferences after a break in numbering are ignored, and ballots without a
   * first preference are informal.
   */
  public function testBallotsStopAtFirstGap() {
    $ballots = array_merge(
      $this->ballots(4, [self::A]),
      [[1 => self::B, 3 => self::C], [1 => self::B, 3 => self::C]],
      $this->ballots(3, [self::C]),
      [[], [2 => self::A]]
    );
    $result = $this->runCount(1, [self::A, self::B, self::C], $ballots);

    $this->assertEquals(5, $result['quota']);
    $this->assertEquals([self::A], $result['elected']);
    $this->assertEquals([self::B], $result['excluded']);
  }

  /**
   * A tie that no earlier count separates is broken by random draw.
   */
  public function testUnresolvableTieIsDrawn() {
    $ballots = array_merge(
      $this->ballots(1, [self::A]),
      $this->ballots(1, [self::B]),
      $this->ballots(2, [self::C])
    );
    $result = $this->runCount(1, [self::A, self::B, self::C], $ballots);

    $this->assertEquals([self::C], $result['elected']);
    $this->assertCount(1, $result['excluded']);
    $this->assertContains($result['excluded'][0], [self::A, self::B]);
    $this->assertTrue($result['tie_by_lot']);
  }

  private function runCount($seats, $candidateIds, $ballots) {
    $count = new CRM_Elections_Helper_SenateCount($seats, $candidateIds, $ballots);
    return $count->run();
  }

  /**
   * Build $n identical ballots numbering $preferences from 1.
   */
  private function ballots($n, $preferences) {
    $ballot = array_combine(range(1, count($preferences)), $preferences);
    return array_fill(0, $n, $ballot);
  }

}
