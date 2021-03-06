<?php namespace webservices\rest\srv;



/**
 * Parameter source
 *
 */
class RestParamSource extends \lang\Object {
  public $name;
  public $reader;

  /**
   * Creates a new param source instance
   *
   * @param  string name
   * @param  webservices.rest.srv.ParamReader reader
   */
  public function __construct($name, $reader) {
    $this->name= $name;
    $this->reader= $reader;
  }

  /**
   * Creates a string representation
   *
   * @return string
   */
  public function toString() {
    return $this->reader->name().($this->name ? "('".$this->name."')" : '');
  }

  /**
   * Returns whether a given instance is equal to this
   *
   * @param  var cmp
   * @return bool
   */
  public function equals($cmp) {
    return (
      $cmp instanceof self &&
      $cmp->name === $this->name &&
      $cmp->reader->equals($this->reader)
    );
  }
}
