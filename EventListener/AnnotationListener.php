<?php
namespace Oka\ApiBundle\EventListener;

use Doctrine\Common\Annotations\Reader;
use Oka\ApiBundle\Annotation\AccessControl;
use Oka\ApiBundle\Annotation\RequestContent;
use Oka\ApiBundle\Service\ErrorResponseFactory;
use Oka\ApiBundle\Util\LoggerHelper;
use Oka\ApiBundle\Util\RequestUtil;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Oka\ApiBundle\Annotation\RequestAudit;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * 
 * @author Cedrick Oka Baidai <okacedrick@gmail.com>
 * 
 */
class AnnotationListener extends LoggerHelper implements EventSubscriberInterface
{
	/**
	 * @var Reader $reader
	 */
	protected $reader;
	
	/**
	 * @var ValidatorInterface $validator
	 */
	protected $validator;
	
	/**
	 * @var TokenStorage $tokenStorage
	 */
	protected $tokenStorage;
	
	/**
	 * @var AuthorizationChecker $authorizationChecker
	 */
	protected $authorizationChecker;
	
	/**
	 * @var TranslatorInterface $translator
	 */
	protected $translator;
	
	/**
	 * @var ErrorResponseFactory $errorFactory
	 */
	protected $errorFactory;
	
	/**
	 * @var RequestDataCollector $dataCollector
	 */
	protected $dataCollector;
	
	/**
	 * @var Profiler $profiler
	 */
	protected $profiler;
	
	/**
	 * @var DocumentManager $dm
	 */
	protected $dm;
	
	/**
	 * @param Reader 				$reader
	 * @param AuthorizationChecker 	$authorizationChecker
	 * @param TokenStorage 			$tokenStorage
	 * @param ValidatorInterface 	$validator
	 * @param TranslatorInterface 	$translator
	 * @param ErrorResponseFactory 	$errorFactory
	 */
	public function __construct(Reader $reader, AuthorizationChecker $authorizationChecker, TokenStorage $tokenStorage, ValidatorInterface $validator, TranslatorInterface $translator, ErrorResponseFactory $errorFactory, Profiler $profiler, DocumentManager $dm = null)
	{
		$this->reader = $reader;
		$this->authorizationChecker = $authorizationChecker;
		$this->tokenStorage = $tokenStorage;
		$this->validator = $validator;
		$this->translator = $translator;
		$this->errorFactory = $errorFactory;
		
		$this->profiler = $profiler;
// 		$this->dm = $dm;
	}
	
	/**
	 * @param FilterControllerEvent $event
	 */
	public function onAccessControlAnnotation(FilterControllerEvent $event)
	{
		if (!$event->isMasterRequest() || !is_array($controller = $event->getController())) {
			return;
		}
		
		if ($annotation = $this->reader->getMethodAnnotation(new \ReflectionMethod($controller[0], $controller[1]), AccessControl::class)) {
			$request = $event->getRequest();
			$acceptablesContentTypes = $request->getAcceptableContentTypes();
			
			// Configure acceptable content type of response
			if (empty($acceptablesContentTypes) || in_array('*/*', $acceptablesContentTypes, true)) {
				$request->attributes->set('format', $annotation->getFormats()[0]);
			} else {
				foreach ($acceptablesContentTypes as $contentType) {
					$format = $request->getFormat($contentType);
						
					if (in_array($format, $annotation->getFormats(), true)) {
						$request->attributes->set('format', $format);
						break;
					}
				}
			}
			
			$response = null;
			$version = $request->attributes->get('version');
			$protocol = $request->attributes->get('protocol');
			$format = RequestUtil::getFirstAcceptableFormat($request) ?: 'json';
			
			if (!version_compare($version, $annotation->getVersion(), $annotation->getVersionOperator())) {
				$response = $this->errorFactory->create($this->translator->trans('response.not_acceptable_api_version', ['%version%' => $version], 'OkaApiBundle'), 406, null, [], 406, [], $format);
			} elseif (strtolower($protocol) !== $annotation->getProtocol()) {
				$response = $this->errorFactory->create($this->translator->trans('response.not_acceptable_protocol', ['%protocol%' => $protocol], 'OkaApiBundle'), 406, null, [], 406, [], $format);
			} elseif (!$request->attributes->has('format')) {
				$response = $this->errorFactory->create($this->translator->trans('response.not_acceptable_format', ['%formats%' => implode(', ', $request->getAcceptableContentTypes())], 'OkaApiBundle'), 406, null, [], 406, [], $format);
			}
			
			if ($response !== null) {
				$event->stopPropagation();
				$event->setController(function() use ($response) {
					return $response;
				});
			}
		}
	}
	
	/**
	 * @param FilterControllerEvent $event
	 */
	public function onRequestContentAnnotation(FilterControllerEvent $event)
	{
		if (!$event->isMasterRequest() || !is_array($controller = $event->getController())) {
			return;
		}
		
		if ($annotation = $this->reader->getMethodAnnotation(new \ReflectionMethod($controller[0], $controller[1]), RequestContent::class)) {
			$validationHasFailed = false;
			$request = $event->getRequest();
			
			// Retrieve query paramters in URI or request content
			$requestContent = $request->isMethod('GET') ? $request->query->all() : RequestUtil::getContentLikeArray($request);
			
			if (true === $annotation->isEnableValidation()) {
				if (!empty($requestContent)) {
					$constraints = $annotation->getConstraints();
					$reflectionMethod = new \ReflectionMethod($controller[0], $constraints);
					
					if (false === $reflectionMethod->isStatic()) {
						throw new \InvalidArgumentException(sprintf('Invalid option(s) passed to @%s: Constraints method "%s" is not static.', RequestContent::class, $constraints));
					}
					
					if ($reflectionMethod->getNumberOfParameters() > 0) {
						throw new \InvalidArgumentException(sprintf('Invalid option(s) passed to @%s: Constraints method "%s" must not have of arguments.', RequestContent::class, $constraints));
					}
					
					$reflectionMethod->setAccessible(true);
					$errors = $this->validator->validate($requestContent, $reflectionMethod->invoke(null));
					$validationHasFailed = $errors->count() > 0;
				} else {
					$validationHasFailed = !$annotation->isCanBeEmpty();
				}
			}
			
			if ($validationHasFailed === true) {
				$event->setController(function(Request $request) use ($errors) {
					$format = $request->attributes->has('format') ? $request->attributes->get('format') : RequestUtil::getFirstAcceptableFormat($request) ?: 'json';
					return $this->errorFactory->createFromConstraintViolationList($errors, $this->translator->trans('response.bad_request', [], 'OkaApiBundle'), 400, null, [], 400, [], $format);
				});
			} else {
				$request->attributes->set('requestContent', $requestContent);
			}
		}
	}
	
	/**
	 * @todo Save request authenticated user and request info and start request duration counter.
	 * 
	 * @param FilterControllerEvent $event
	 * @param string $eventName
	 * @param EventDispatcherInterface $dispatcher
	 */
	public function onRequestAuditAnnotation(KernelEvent $event, $eventName, EventDispatcherInterface $dispatcher)
	{
		if (!$event->isMasterRequest()) {
			return;
		}
		
		switch (true) {
			case $event instanceof FilterControllerEvent:
				if (!is_array($controller = $event->getController())) {
					return;
				}
				
				if (!$this->reader->getClassAnnotation(new \ReflectionClass($controller[0]), RequestAudit::class)) {
					if (!$this->reader->getMethodAnnotation(new \ReflectionMethod($controller[0], $controller[1]), RequestAudit::class)) {
						return;
					}
				}
				
				$this->dataCollector = new RequestDataCollector();
				$this->dataCollector->onKernelController($event);
				$dispatcher->addListener(KernelEvents::TERMINATE, [$this, 'onRequestAuditAnnotation']);
				
				break;
			case $event instanceof PostResponseEvent:
				$this->dataCollector->collect($event->getRequest(), $event->getResponse());
				
				if ($this->authorizationChecker->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
					$user = $this->tokenStorage->getToken()->getUser();
				}
				
				break;
		}
	}
	
	public function onKernelTerminate(PostResponseEvent $event, $eventName, EventDispatcherInterface $dispatcher) {
		/** @var \Symfony\Component\HttpKernel\Profiler\Profile $profile */
		if (!$profile = $this->profiler->loadProfileFromResponse($event->getResponse())) {
			$profile = $this->profiler->collect($event->getRequest(), $event->getResponse());
		}
		
		$connection = $this->dm->getConnection();
		$db = $connection->selectDatabase('aynid_engine');
		$collection = $db->selectCollection('profiler');
		
		$collection->update(['_id' => $profile->getToken()], [], ['upsert' => true]);
	}
	
	public static function getSubscribedEvents()
	{
		return [
				KernelEvents::CONTROLLER => [
// 						['onRequestAuditAnnotation', 3],
						['onAccessControlAnnotation', 2], 
						['onRequestContentAnnotation', 1]
				],
// 				KernelEvents::TERMINATE => [
// 						['onKernelTerminate', 3]
// 				]
		];
	}
	
	private function getAnnotations() {
		
	}
}