<?php
namespace Oka\ApiBundle\Service;

use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Oka\ApiBundle\Util\ErrorResponseBuilder;
use Oka\ApiBundle\Util\ErrorResponseBuilderInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 
 * @author Cedrick Oka <okacedrick@gmail.com>
 *
 */
class ErrorResponseFactory
{
	/**
	 * @var string $builderClass
	 */
	protected $builderClass;
	
	/**
	 * Constructor.
	 * 
	 * @param string $builderClass
	 */
	public function __construct($builderClass = null)
	{
		$this->builderClass = $builderClass;
	}
	
	/**
	 * Create new instance
	 * 
	 * @param string $message
	 * @param int $code
	 * @param string $property
	 * @param array $extras
	 * @param int $httpStatusCode
	 * @param array $httpHeaders
	 * @param string $format
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function create($message, $code = 500, $property = null, array $extras = [], $httpStatusCode = 500, $httpHeaders = [], $format = 'json')
	{
		return $this->getBuilderInstance()
					->setError($message, $code, $property, $extras)
					->setHttpSatusCode($httpStatusCode)
					->setHttpHeaders($httpHeaders)
					->setFormat($format)
					->build();
	}
	
	/**
	 * Create new instance from FormErrorIterator class
	 * 
	 * @param FormErrorIterator $errors
	 * @param string $message
	 * @param int $code
	 * @param string $property
	 * @param array $extras
	 * @param int $httpStatusCode
	 * @param array $httpHeaders
	 * @param string $format
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function createFromFormErrorIterator(FormErrorIterator $errors, $message = null, $code = 400, $property = null, array $extras = [], $httpStatusCode = 400, $httpHeaders = [], $format = 'json')
	{
		$builder = $this->getBuilderInstance()
						->setError($message ?: 'Request not valid!', $code, $property, $extras)
						->setHttpSatusCode($httpStatusCode)
						->setHttpHeaders($httpHeaders)
						->setFormat($format);
		
		/** @var \Symfony\Component\Form\FormError $error */
		foreach ($errors as $error) {
			$builder->addChildError($error->getMessage(), 0, $error->getOrigin()->getPropertyPath()->__toString(), $error->getCause() ? ['cause' => $error->getCause()] : []);
		}
		
		return $builder->build();
	}
	
	/**
	 * Create new instance from ConstraintViolationList class
	 * 
	 * @param ConstraintViolationListInterface $errors
	 * @param string $message
	 * @param int $code
	 * @param string $property
	 * @param array $extras
	 * @param int $httpStatusCode
	 * @param array $httpHeaders
	 * @param string $format
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function createFromConstraintViolationList(ConstraintViolationListInterface $errors, $message = null, $code = 400, $property = null, array $extras = [], $httpStatusCode = 400, $httpHeaders = [], $format = 'json')
	{
		$builder = $this->getBuilderInstance()
						->setError($message ?: 'Request not valid!', $code, $property, $extras)
						->setHttpSatusCode($httpStatusCode)
						->setHttpHeaders($httpHeaders)
						->setFormat($format);
		
		/** @var \Symfony\Component\Validator\ConstraintViolationInterface $error */
		foreach ($errors as $error) {
			$builder->addChildError($error->getMessage(), (int) $error->getCode(), $error->getPropertyPath(), ['invalidValue' => $error->getInvalidValue()]);
		}
		
		return $builder->build();
	}
	
	/**
	 * Create new instance from Exception class
	 * 
	 * @param \Exception $exception
	 * @param string $property
	 * @param array $extras
	 * @param int $httpStatusCode
	 * @param array $httpHeaders
	 * @param string $format
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function createFromException(\Exception $exception, $property = null, array $extras = [], $httpStatusCode = null, $httpHeaders = [], $format = 'json')
	{
		if ($exception instanceof HttpExceptionInterface) {
			$httpStatusCode = $httpStatusCode ?: $exception->getStatusCode();
			$httpHeaders = $httpHeaders ?: $exception->getHeaders();
		} else {
			$httpStatusCode = $httpStatusCode ?: 500;
		}
		
		return $this->getBuilderInstance()
					->setError($exception->getMessage(), (int) $exception->getCode(), $property, $extras)
					->setHttpSatusCode($httpStatusCode)
					->setHttpHeaders($httpHeaders)
					->setFormat($format)
					->build();
	}
	
	/**
	 * @return \Oka\ApiBundle\Util\ErrorResponseBuilderInterface
	 */
	protected function getBuilderInstance()
	{
		if ($this->builderClass === null) {
			return ErrorResponseBuilder::getInstance();
		}
		
		$reflClass = new \ReflectionClass($this->builderClass);
		if (false === $reflClass->implementsInterface(ErrorResponseBuilderInterface::class)) {
			throw new \UnexpectedValueException(sprintf('The builder class must implementing interface "%s", "%s" given.',ErrorResponseBuilderInterface::class, $this->builderClass));
		}
		
		$reflMethod = new \ReflectionMethod($this->builderClass, 'getInstance');
		return $reflMethod->invoke();
	}
}
