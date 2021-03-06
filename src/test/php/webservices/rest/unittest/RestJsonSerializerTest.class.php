<?php namespace webservices\rest\unittest;

use unittest\TestCase;
use webservices\rest\RestJsonSerializer;
use util\Date;
use util\TimeZone;
use io\streams\MemoryOutputStream;

/**
 * TestCase
 *
 * @see   xp://webservices.rest.RestJsonSerializer
 */
class RestJsonSerializerTest extends TestCase {

  /**
   * Serialization helper
   *
   * @param  var $value
   * @return string
   */
  protected function serialize($value) {
    $out= new MemoryOutputStream();
    (new RestJsonSerializer())->serialize($value, $out);
    return $out->getBytes();
  }

  #[@test]
  public function null() {
    $this->assertEquals('null', $this->serialize(null));
  }

  #[@test, @values(['', 'Test'])]
  public function strings($str) {
    $this->assertEquals('"'.$str.'"', $this->serialize($str));
  }

  #[@test, @values([['-1', -1], ['0', 0], ['1', 1], ['4711', 4711]])]
  public function integers($expected, $int) {
    $this->assertEquals($expected, $this->serialize($int));
  }

  #[@test, @values([['-1.0', -1.0], ['0.0', 0.0], ['1.0', 1.0], ['47.11', 47.11]])]
  public function decimals($expected, $decimal) {
    $this->assertEquals($expected, $this->serialize($decimal));
  }

  #[@test]
  public function boolean_true() {
    $this->assertEquals('true', $this->serialize(true));
  }

  #[@test]
  public function boolean_false() {
    $this->assertEquals('false', $this->serialize(false));
  }

  #[@test]
  public function empty_array() {
    $this->assertEquals('[]', $this->serialize([]));
  }

  #[@test]
  public function int_array() {
    $this->assertEquals('[1,2,3]', $this->serialize([1, 2, 3]));
  }

  #[@test]
  public function string_array() {
    $this->assertEquals('["a","b","c"]', $this->serialize(['a', 'b', 'c']));
  }

  #[@test]
  public function string_map() {
    $this->assertEquals(
      '{"a":"One","b":"Two","c":"Three"}',
      $this->serialize(['a' => 'One', 'b' => 'Two', 'c' => 'Three'])
    );
  }

  #[@test, @values([
  #  [new \ArrayIterator([1, 2, 3])],
  #  [new \ArrayObject([1, 2, 3])]
  #])]
  public function traversable_array($in) {
    $this->assertEquals(
      '[1,2,3]',
      $this->serialize($in)
    );
  }

  #[@test, @values([
  #  [new \ArrayIterator(['color' => 'green', 'price' => '$12.99'])],
  #  [new \ArrayObject(['color' => 'green', 'price' => '$12.99'])]
  #])]
  public function traversable_map($in) {
    $this->assertEquals(
      '{"color":"green","price":"$12.99"}',
      $this->serialize($in)
    );
  }

  #[@test, @values([
  #  [new \ArrayIterator([])],
  #  [new \ArrayObject([])]
  #])]
  public function empty_traversable($in) {
    $this->assertEquals(
      '[]',
      $this->serialize($in)
    );
  }
}
