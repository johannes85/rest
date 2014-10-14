<?php namespace webservices\rest\unittest\srv;

use unittest\TestCase;
use scriptlet\HttpScriptletRequest;
use scriptlet\HttpScriptletResponse;
use scriptlet\Cookie;
use webservices\rest\srv\RestContext;
use util\log\Logger;
use util\log\LogCategory;
use lang\reflect\Package;

/**
 * Test default router
 *
 * @see  xp://webservices.rest.srv.RestDefaultRouter
 */
class RestContextTest extends TestCase {
  protected $fixture= null;
  protected static $package= null;

  /**
   * Sets up fixture package
   */
  #[@beforeClass]
  public static function fixturePackage() {
    self::$package= Package::forName('webservices.rest.unittest.srv.fixture');
  }

  /**
   * Setup
   */
  public function setUp() {
    $this->fixture= new RestContext();
  }

  /**
   * Returns a class object for a given fixture class
   *
   * @param  string $class
   * @return lang.XPClass
   */
  protected function fixtureClass($class) {
    return self::$package->loadClass($class);
  }

  /**
   * Returns a method from given fixture class
   *
   * @param  string $class
   * @param  string $method
   * @return lang.reflect.Method
   */
  protected function fixtureMethod($class, $method) {
    return self::$package->loadClass($class)->getMethod($method);
  }

  /**
   * Creates a new request with a given parameter map
   *
   * @param  [:string] params
   * @return scriptlet.Request
   */
  protected function newRequest($params= array(), $payload= null, $headers= array()) {
    $r= newinstance('scriptlet.HttpScriptletRequest', array($payload), '{
      public function __construct($payload) {
        if (NULL !== $payload) {
          $this->inputStream= new \io\streams\MemoryInputStream($payload);
        }
      }
    }');
    foreach ($params as $name => $value) {
      $r->setParam($name, $value);
    }
    if (isset($headers['Cookie'])) {
      foreach (explode(';', $headers['Cookie']) as $cookie) {
        sscanf(trim($cookie), '%[^=]=%s', $name, $value);
        $r->addCookie(new Cookie($name, $value));
      }
      unset($headers['Cookie']);
    }
    $r->setHeaders($headers);
    return $r;
  }

  /**
   * Assertion helper
   *
   * @param  int $status Expected status
   * @param  string[] $headers Expected headers
   * @param  string $content Expected content
   * @param  [:var] $route Route
   * @param  scriptlet.Request $request HTTP request
   * @throws unittest.AssertionFailedError
   */
  protected function assertProcess($status, $headers, $content, $route, $request) {
    $response= new HttpScriptletResponse();
    $this->fixture->process($route, $request, $response);
    $this->assertEquals($status, $response->statusCode, 'Status code');
    $this->assertEquals($headers, $response->headers, 'Headers');
    $this->assertEquals($content, $response->content, 'Content');
  }

  #[@test]
  public function marshal_this_generically() {
    $this->assertEquals(
      new \webservices\rest\Payload(array('name' => $this->name)),
      $this->fixture->marshal(new \webservices\rest\Payload($this))
    );
  }

  #[@test]
  public function marshal_this_with_typemarshaller() {
    $this->fixture->addMarshaller('unittest.TestCase', newinstance('webservices.rest.TypeMarshaller', array(), '{
      public function marshal($t) {
        return $t->getName();
      }
      public function unmarshal(\lang\Type $target, $name) {
        // Not needed
      }
    }'));
    $this->assertEquals(
      new \webservices\rest\Payload($this->getName()),
      $this->fixture->marshal(new \webservices\rest\Payload($this))
    );
  }

  #[@test]
  public function unmarshal_this_with_typemarshaller() {
    $this->fixture->addMarshaller('unittest.TestCase', newinstance('webservices.rest.TypeMarshaller', array(), '{
      public function marshal($t) {
        // Not needed
      }
      public function unmarshal(\lang\Type $target, $name) {
        return $target->newInstance($name);
      }
    }'));
    $this->assertEquals(
      $this,
      $this->fixture->unmarshal($this->getClass(), $this->getName())
    );
  }

  #[@test]
  public function handle_xmlfactory_annotated_method() {
    $handler= newinstance('lang.Object', array(), '{
      #[@webmethod, @xmlfactory(element = "book")]
      public function getBook() {
        return array("isbn" => "978-3-16-148410-0", "author" => "Test");
      }
    }');
    $this->assertEquals(
      \webservices\rest\srv\Response::error(200)->withPayload(new \webservices\rest\Payload(array('isbn' => '978-3-16-148410-0', 'author' => 'Test'), array('name' => 'book'))),
      $this->fixture->handle($handler, $handler->getClass()->getMethod('getBook'), array())
    );
  }

  #[@test]
  public function handle_xmlfactory_annotated_class() {
    $handler= newinstance('#[@webservice, @xmlfactory(element= "greeting")] lang.Object', [], '{
      #[@webmethod]
      public function greet() { return "Test"; }
    }');
    $this->assertEquals(
      \webservices\rest\srv\Response::error(200)->withPayload(new \webservices\rest\Payload('Test', array('name' => 'greeting'))),
      $this->fixture->handle($handler, $handler->getClass()->getMethod('greet'), array())
    );
  }

  #[@webmethod]
  public function raiseAnError($t) {
    throw $t;
  }

  #[@test]
  public function handle_exception_with_mapper() {
    $t= new \lang\Throwable('Test');
    $this->fixture->addExceptionMapping('lang.Throwable', newinstance('webservices.rest.srv.ExceptionMapper', array(), '{
      public function asResponse($t, RestContext $ctx) {
        return Response::error(500)->withPayload(array("message" => $t->getMessage()));
      }
    }'));
    $this->assertEquals(
      \webservices\rest\srv\Response::status(500)->withPayload(new \webservices\rest\Payload(array('message' => 'Test'), array('name' => 'exception'))),
      $this->fixture->handle($this, $this->getClass()->getMethod('raiseAnError'), array($t))
    );
  }

  #[@test]
  public function constructor_injection() {
    $class= \lang\ClassLoader::defineClass('AbstractRestRouterTest_ConstructorInjection', 'lang.Object', array(), '{
      protected $context;
      #[@inject(type = "webservices.rest.srv.RestContext")]
      public function __construct($context) { $this->context= $context; }
      public function equals($cmp) { return $cmp instanceof self && $this->context->equals($cmp->context); }
    }');
    $this->assertEquals(
      $class->newInstance($this->fixture),
      $this->fixture->handlerInstanceFor($class)
    );
  }

  #[@test]
  public function typename_injection() {
    $class= \lang\ClassLoader::defineClass('AbstractRestRouterTest_TypeNameInjection', 'lang.Object', array(), '{
      protected $context;

      /** @param webservices.rest.srv.RestContext context */
      #[@inject]
      public function __construct($context) { $this->context= $context; }
      public function equals($cmp) { return $cmp instanceof self && $this->context->equals($cmp->context); }
    }');
    $this->assertEquals(
      $class->newInstance($this->fixture),
      $this->fixture->handlerInstanceFor($class)
    );
  }

  #[@test]
  public function typerestriction_injection() {
    $class= \lang\ClassLoader::defineClass('AbstractRestRouterTest_TypeRestrictionInjection', 'lang.Object', array(), '{
      protected $context;

      #[@inject]
      public function __construct(\webservices\rest\srv\RestContext $context) { $this->context= $context; }
      public function equals($cmp) { return $cmp instanceof self && $this->context->equals($cmp->context); }
    }');
    $this->assertEquals(
      $class->newInstance($this->fixture),
      $this->fixture->handlerInstanceFor($class)
    );
  }

  #[@test]
  public function setter_injection() {
    $prop= new \util\Properties('service.ini');
    \util\PropertyManager::getInstance()->register('service', $prop);
    $class= \lang\ClassLoader::defineClass('AbstractRestRouterTest_SetterInjection', 'lang.Object', array(), '{
      public $prop;
      #[@inject(type = "util.Properties", name = "service")]
      public function setServiceConfig($prop) { $this->prop= $prop; }
    }');
    $this->assertEquals(
      $prop,
      $this->fixture->handlerInstanceFor($class)->prop
    );
  }

  #[@test]
  public function unnamed_logcategory_injection() {
    $class= \lang\ClassLoader::defineClass('AbstractRestRouterTest_UnnamedLogcategoryInjection', 'lang.Object', array(), '{
      public $cat;
      #[@inject(type = "util.log.LogCategory")]
      public function setTrace($cat) { $this->cat= $cat; }
    }');
    $cat= new LogCategory('test');
    $this->fixture->setTrace($cat);
    $this->assertEquals(
      $cat,
      $this->fixture->handlerInstanceFor($class)->cat
    );
  }

  #[@test]
  public function named_logcategory_injection() {
    $class= \lang\ClassLoader::defineClass('AbstractRestRouterTest_NamedLogcategoryInjection', 'lang.Object', array(), '{
      public $cat;
      #[@inject(type = "util.log.LogCategory", name = "test")]
      public function setTrace($cat) { $this->cat= $cat; }
    }');
    $cat= Logger::getInstance()->getCategory('test');
    $this->assertEquals(
      $cat,
      $this->fixture->handlerInstanceFor($class)->cat
    );
  }

  #[@test, @expect(class = 'lang.reflect.TargetInvocationException', withMessage= '/InjectionError::setTrace/')]
  public function injection_error() {
    $class= \lang\ClassLoader::defineClass('AbstractRestRouterTest_InjectionError', 'lang.Object', array(), '{
      #[@inject(type = "util.log.LogCategory")]
      public function setTrace($cat) { throw new \lang\IllegalStateException("Test"); }
    }');
    $this->fixture->handlerInstanceFor($class);
  }

  #[@test, @expect(class = 'lang.reflect.TargetInvocationException', withMessage= '/InstantiationError::<init>/')]
  public function instantiation_error() {
    $class= \lang\ClassLoader::defineClass('AbstractRestRouterTest_InstantiationError', 'lang.Object', array(), '{
      public function __construct() { throw new \lang\IllegalStateException("Test"); }
    }');
    $this->fixture->handlerInstanceFor($class);
  }


  #[@test]
  public function greet_implicit_segment_and_param() {
    $route= array(
      'handler'  => $this->fixtureClass('ImplicitGreetingHandler'),
      'target'   => $this->fixtureMethod('ImplicitGreetingHandler', 'greet'),
      'params'   => array(),
      'segments' => array(0 => '/implicit/greet/test', 'name' => 'test', 1 => 'test'),
      'input'    => null,
      'output'   => 'text/json'
    );
    $this->assertEquals(
      array('test', 'Servus'),
      $this->fixture->argumentsFor($route, $this->newRequest(array('greeting' => 'Servus')), \webservices\rest\RestFormat::$FORM)
    );
  }

  #[@test]
  public function greet_implicit_segment_and_missing_param() {
    $route= array(
      'handler'  => $this->fixtureClass('ImplicitGreetingHandler'),
      'target'   => $this->fixtureMethod('ImplicitGreetingHandler', 'greet'),
      'params'   => array(),
      'segments' => array(0 => '/implicit/greet/test', 'name' => 'test', 1 => 'test'),
      'input'    => null,
      'output'   => 'text/json'
    );
    $this->assertEquals(
      array('test', 'Hello'),
      $this->fixture->argumentsFor($route, $this->newRequest(), \webservices\rest\RestFormat::$FORM)
    );
  }

  #[@test]
  public function greet_implicit_payload() {
    $route= array(
      'handler'  => $this->fixtureClass('ImplicitGreetingHandler'),
      'target'   => $this->fixtureMethod('ImplicitGreetingHandler', 'greet_posted'),
      'params'   => array(),
      'segments' => array(0 => '/greet'),
      'input'    => 'application/json',
      'output'   => 'text/json'
    );
    $this->assertEquals(
      array('Hello World'),
      $this->fixture->argumentsFor($route, $this->newRequest(array(), '"Hello World"'), \webservices\rest\RestFormat::$JSON)
    );
  }

  #[@test]
  public function greet_intl() {
    $route= array(
      'handler'  => $this->fixtureClass('GreetingHandler'),
      'target'   => $this->fixtureMethod('GreetingHandler', 'greet_intl'),
      'params'   => array('language' => new \webservices\rest\srv\RestParamSource('Accept-Language', \webservices\rest\srv\ParamReader::$HEADER)),
      'segments' => array(0 => '/intl/greet/test', 'name' => 'test', 1 => 'test'),
      'input'    => null,
      'output'   => 'text/json'
    );
    $this->assertEquals(
      array('test', new \scriptlet\Preference('de')),
      $this->fixture->argumentsFor($route, $this->newRequest(array(), null, array('Accept-Language' => 'de')), \webservices\rest\RestFormat::$FORM)
    );
  }

  #[@test]
  public function greet_user() {
    $route= array(
      'handler'  => $this->fixtureClass('GreetingHandler'),
      'target'   => $this->fixtureMethod('GreetingHandler', 'greet_user'),
      'params'   => array('name' => new \webservices\rest\srv\RestParamSource('user', \webservices\rest\srv\ParamReader::$COOKIE)),
      'segments' => array(0 => '/user/greet'),
      'input'    => null,
      'output'   => 'text/json'
    );
    $this->assertEquals(
      array('Test'),
      $this->fixture->argumentsFor($route, $this->newRequest(array(), null, array('Cookie' => 'user=Test')), \webservices\rest\RestFormat::$FORM)
    );
  }


  #[@test]
  public function process_greet_successfully() {
    $route= array(
      'handler'  => $this->fixtureClass('GreetingHandler'),
      'target'   => $this->fixtureMethod('GreetingHandler', 'greet'),
      'params'   => array('name' => new \webservices\rest\srv\RestParamSource('name', \webservices\rest\srv\ParamReader::$PATH)),
      'segments' => array(0 => '/greet/Test', 'name' => 'Test', 1 => 'Test'),
      'input'    => null,
      'output'   => 'text/json'
    );
    $this->assertProcess(
      200, array('Content-Type: text/json'), '"Hello Test"',
      $route, $this->newRequest()
    );
  }

  #[@test]
  public function process_greet_with_missing_parameter() {
    $route= array(
      'handler'  => $this->fixtureClass('GreetingHandler'),
      'target'   => $this->fixtureMethod('GreetingHandler', 'greet'),
      'params'   => array('name' => new \webservices\rest\srv\RestParamSource('name', \webservices\rest\srv\ParamReader::$PATH)),
      'segments' => array(0 => '/greet/'),
      'input'    => null,
      'output'   => 'text/json'
    );
    $this->assertProcess(
      400, array('Content-Type: text/json'), '{ "message" : "Parameter \"name\" required, but not found in path(\'name\')" }',
      $route, $this->newRequest()
    );
  }

  #[@test]
  public function process_greet_and_go() {
    $route= array(
      'handler'  => $this->fixtureClass('GreetingHandler'),
      'target'   => $this->fixtureMethod('GreetingHandler', 'greet_and_go'),
      'params'   => array('name' => new \webservices\rest\srv\RestParamSource('name', \webservices\rest\srv\ParamReader::$PATH)), 
      'segments' => array(0 => '/greet/and/go/test', 'name' => 'test', 1 => 'test'),
      'input'    => null,
      'output'   => 'text/json'
    );
    $this->assertProcess(
      204, array(), null,
      $route, $this->newRequest()
    );
  }

  #[@test]
  public function marshal_exceptions() {
    $this->fixture->addMarshaller('unittest.AssertionFailedError', newinstance('webservices.rest.TypeMarshaller', array(), '{
      public function marshal($t) {
        return "assert:".$t->message;
      }
      public function unmarshal(\lang\Type $target, $name) {
        // Not needed
      }
    }'));
    $this->assertEquals(
      \webservices\rest\srv\Response::error(500)->withPayload(new \webservices\rest\Payload('assert:expected 1 but was 2', array('name' => 'exception'))),
      $this->fixture->asResponse(new \unittest\AssertionFailedError('expected 1 but was 2'))
    );
  }

  #[@test]
  public function process_streaming_output() {
    $route= array(
      'handler'  => $this->fixtureClass('GreetingHandler'),
      'target'   => $this->fixtureMethod('GreetingHandler', 'download_greeting'),
      'params'   => array(),
      'segments' => array(0 => '/download'),
      'input'    => null,
      'output'   => null
    );

    $this->assertProcess(
      200, array('Content-Type: text/plain; charset=utf-8', 'Content-Length: 11'), 'Hello World',
      $route, $this->newRequest()
    );
  }

  #[@test]
  public function process_extended() {
    $extended= \lang\ClassLoader::defineClass(
      'webservices.rest.unittest.srv.fixture.GreetingHandlerExtended',
      $this->fixtureClass('GreetingHandler')->getName(),
      array(),
      '{}'
    );

    $route= array(
      'handler'  => $extended,
      'target'   => $extended->getMethod('greet_class'),
      'params'   => array(),
      'segments' => array(0 => '/greet/class'),
      'input'    => null,
      'output'   => 'text/json'
    );

    $this->assertProcess(
      200, array('Content-Type: text/json'), '"Hello '.$extended->getName().'"',
      $route, $this->newRequest()
    );
  }

  #[@test]
  public function add_exception_mapping_returns_added_mapping() {
    $mapping= newinstance('webservices.rest.srv.ExceptionMapper', array(), '{
      public function asResponse($t, RestContext $ctx) {
        return Response::error(500)->withPayload(array("message" => $t->getMessage()));
      }
    }');
    $this->assertEquals($mapping, $this->fixture->addExceptionMapping('lang.Throwable', $mapping));
  }

  #[@test]
  public function get_exception_mapping() {
    $mapping= newinstance('webservices.rest.srv.ExceptionMapper', array(), '{
      public function asResponse($t, RestContext $ctx) {
        return Response::error(500)->withPayload(array("message" => $t->getMessage()));
      }
    }');
    $this->fixture->addExceptionMapping('lang.Throwable', $mapping);
    $this->assertEquals($mapping, $this->fixture->getExceptionMapping('lang.Throwable'));
  }

  #[@test]
  public function get_non_existant_exception_mapping() {
    $this->assertNull($this->fixture->getExceptionMapping('unittest.AssertionFailedError'));
  }

  #[@test]
  public function add_marshaller_returns_added_marshaller() {
    $marshaller= newinstance('webservices.rest.TypeMarshaller', array(), '{
      public function marshal($t) {
        return $t->getName();
      }
      public function unmarshal(\lang\Type $target, $name) {
        // Not needed
      }
    }');
    $this->assertEquals($marshaller, $this->fixture->addMarshaller('unittest.TestCase', $marshaller));
  }

  #[@test]
  public function get_marshaller() {
    $marshaller= newinstance('webservices.rest.TypeMarshaller', array(), '{
      public function marshal($t) {
        return $t->getName();
      }
      public function unmarshal(\lang\Type $target, $name) {
        // Not needed
      }
    }');
    $this->fixture->addMarshaller('unittest.TestCase', $marshaller);
    $this->assertEquals($marshaller, $this->fixture->getMarshaller('unittest.TestCase'));
  }

  #[@test]
  public function get_non_existant_marshaller() {
    $this->assertNull($this->fixture->getMarshaller('unittest.TestCase'));
  }

  #[@test]
  public function process_exceptions_from_handler_constructor() {
    $route= array(
      'handler'  => $this->fixtureClass('RaisesErrorFromConstructor'),
      'target'   => null,
      'params'   => array(),
      'segments' => array(),
      'input'    => null,
      'output'   => 'text/json'
    );

    $this->assertProcess(
      500, array('Content-Type: text/json'), '{ "message" : "Cannot instantiate" }',
      $route, $this->newRequest()
    );
  }

  #[@test]
  public function process_exceptions_from_handler_constructor_are_not_mapped() {
    $route= array(
      'handler'  => $this->fixtureClass('RaisesExceptionFromConstructor'),
      'target'   => null,
      'params'   => array(),
      'segments' => array(),
      'input'    => null,
      'output'   => 'text/json'
    );

    $this->assertProcess(
      500, array('Content-Type: text/json'), '{ "message" : "Cannot instantiate" }',
      $route, $this->newRequest()
    );
  }

  #[@test]
  public function process_errors_from_handler_method() {
    $route= array(
      'handler'  => $this->fixtureClass('RaisesFromMethod'),
      'target'   => $this->fixtureMethod('RaisesFromMethod', 'error'),
      'params'   => array(),
      'segments' => array(),
      'input'    => null,
      'output'   => 'text/json'
    );

    $this->assertProcess(
      500, array('Content-Type: text/json'), '{ "message" : "Invocation failed" }',
      $route, $this->newRequest()
    );
  }

  #[@test]
  public function process_exceptions_from_handler_method_are_mapped() {
    $route= array(
      'handler'  => $this->fixtureClass('RaisesFromMethod'),
      'target'   => $this->fixtureMethod('RaisesFromMethod', 'exception'),
      'params'   => array(),
      'segments' => array(),
      'input'    => null,
      'output'   => 'text/json'
    );

    $this->assertProcess(
      409, array('Content-Type: text/json'), '{ "message" : "Invocation failed" }',
      $route, $this->newRequest()
    );
  }
}
