<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Extbase\Mvc\Controller;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Event\Mvc\BeforeActionCallEvent;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\Exception\RequiredArgumentMissingException;
use TYPO3\CMS\Extbase\Mvc\Exception\InvalidArgumentTypeException;
use TYPO3\CMS\Extbase\Mvc\Exception\NoSuchActionException;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\CMS\Extbase\Mvc\View\GenericViewResolver;
use TYPO3\CMS\Extbase\Mvc\View\JsonView;
use TYPO3\CMS\Extbase\Mvc\View\NotFoundView;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Mvc\View\ViewResolverInterface;
use TYPO3\CMS\Extbase\Mvc\Web\ReferringRequest;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;
use TYPO3\CMS\Extbase\Property\Exception\TargetNotFoundException;
use TYPO3\CMS\Extbase\Property\PropertyMapper;
use TYPO3\CMS\Extbase\Reflection\ReflectionService;
use TYPO3\CMS\Extbase\Security\Cryptography\HashService;
use TYPO3\CMS\Extbase\Service\CacheService;
use TYPO3\CMS\Extbase\Service\ExtensionService;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Extbase\Validation\Validator\ConjunctionValidator;
use TYPO3\CMS\Extbase\Validation\Validator\ValidatorInterface;
use TYPO3\CMS\Extbase\Validation\ValidatorResolver;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3Fluid\Fluid\View\TemplateView;

/**
 * A multi action controller. This is by far the most common base class for Controllers.
 */
abstract class ActionController implements ControllerInterface
{
    /**
     * @var ResponseFactoryInterface
     */
    protected $responseFactory;

    /**
     * @var \TYPO3\CMS\Extbase\Reflection\ReflectionService
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected $reflectionService;

    /**
     * @var \TYPO3\CMS\Extbase\Service\CacheService
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected $cacheService;

    /**
     * @var HashService
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected $hashService;

    /**
     * @var ViewResolverInterface
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    private $viewResolver;

    /**
     * The current view, as resolved by resolveView()
     *
     * @var ViewInterface
     */
    protected $view;

    /**
     * The default view object to use if none of the resolved views can render
     * a response for the current request.
     *
     * @var string
     */
    protected $defaultViewObjectName = \TYPO3\CMS\Fluid\View\TemplateView::class;

    /**
     * Name of the action method
     *
     * @var string
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected $actionMethodName = 'indexAction';

    /**
     * Name of the special error action method which is called in case of errors
     *
     * @var string
     */
    protected $errorMethodName = 'errorAction';

    /**
     * @var \TYPO3\CMS\Extbase\Mvc\Controller\MvcPropertyMappingConfigurationService
     */
    protected $mvcPropertyMappingConfigurationService;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * The current request.
     *
     * @var \TYPO3\CMS\Extbase\Mvc\Request
     */
    protected $request;

    /**
     * @var \TYPO3\CMS\Extbase\SignalSlot\Dispatcher
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected $signalSlotDispatcher;

    /**
     * @var \TYPO3\CMS\Extbase\Object\ObjectManagerInterface
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected $objectManager;

    /**
     * @var \TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder
     */
    protected $uriBuilder;

    /**
     * Contains the settings of the current extension
     *
     * @var array
     */
    protected $settings;

    /**
     * @var \TYPO3\CMS\Extbase\Validation\ValidatorResolver
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected $validatorResolver;

    /**
     * @var \TYPO3\CMS\Extbase\Mvc\Controller\Arguments Arguments passed to the controller
     */
    protected $arguments;

    /**
     * @var \TYPO3\CMS\Extbase\Mvc\Controller\ControllerContext
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected $controllerContext;

    /**
     * @var ConfigurationManagerInterface
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected $configurationManager;

    /**
     * @var PropertyMapper
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    private $propertyMapper;

    /**
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    private FlashMessageService $internalFlashMessageService;

    /**
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    private ExtensionService $internalExtensionService;

    final public function injectResponseFactory(ResponseFactoryInterface $responseFactory)
    {
        $this->responseFactory = $responseFactory;
    }

    /**
     * @param ConfigurationManagerInterface $configurationManager
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function injectConfigurationManager(ConfigurationManagerInterface $configurationManager)
    {
        $this->configurationManager = $configurationManager;
        $this->settings = $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS);
    }

    /**
     * Injects the object manager
     *
     * @param \TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function injectObjectManager(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
        $this->arguments = GeneralUtility::makeInstance(Arguments::class);
    }

    /**
     * @param \TYPO3\CMS\Extbase\SignalSlot\Dispatcher $signalSlotDispatcher
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function injectSignalSlotDispatcher(Dispatcher $signalSlotDispatcher)
    {
        $this->signalSlotDispatcher = $signalSlotDispatcher;
    }

    /**
     * @param \TYPO3\CMS\Extbase\Validation\ValidatorResolver $validatorResolver
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function injectValidatorResolver(ValidatorResolver $validatorResolver)
    {
        $this->validatorResolver = $validatorResolver;
    }

    /**
     * @param ViewResolverInterface $viewResolver
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function injectViewResolver(ViewResolverInterface $viewResolver)
    {
        $this->viewResolver = $viewResolver;
    }

    /**
     * @param \TYPO3\CMS\Extbase\Reflection\ReflectionService $reflectionService
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function injectReflectionService(ReflectionService $reflectionService)
    {
        $this->reflectionService = $reflectionService;
    }

    /**
     * @param \TYPO3\CMS\Extbase\Service\CacheService $cacheService
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function injectCacheService(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * @param HashService $hashService
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function injectHashService(HashService $hashService)
    {
        $this->hashService = $hashService;
    }

    /**
     * @param \TYPO3\CMS\Extbase\Mvc\Controller\MvcPropertyMappingConfigurationService $mvcPropertyMappingConfigurationService
     */
    public function injectMvcPropertyMappingConfigurationService(MvcPropertyMappingConfigurationService $mvcPropertyMappingConfigurationService)
    {
        $this->mvcPropertyMappingConfigurationService = $mvcPropertyMappingConfigurationService;
    }

    public function injectEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @param PropertyMapper $propertyMapper
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function injectPropertyMapper(PropertyMapper $propertyMapper): void
    {
        $this->propertyMapper = $propertyMapper;
    }

    /**
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    final public function injectInternalFlashMessageService(FlashMessageService $flashMessageService): void
    {
        $this->internalFlashMessageService = $flashMessageService;
    }

    /**
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    final public function injectInternalExtensionService(ExtensionService $extensionService): void
    {
        $this->internalExtensionService = $extensionService;
    }

    /**
     * Initializes the view before invoking an action method.
     *
     * Override this method to solve assign variables common for all actions
     * or prepare the view in another way before the action is called.
     *
     * @param ViewInterface $view The view to be initialized
     */
    protected function initializeView(ViewInterface $view)
    {
    }

    /**
     * Initializes the controller before invoking an action method.
     *
     * Override this method to solve tasks which all actions have in
     * common.
     */
    protected function initializeAction()
    {
    }

    /**
     * Implementation of the arguments initialization in the action controller:
     * Automatically registers arguments of the current action
     *
     * Don't override this method - use initializeAction() instead.
     *
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\InvalidArgumentTypeException
     * @see initializeArguments()
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function initializeActionMethodArguments()
    {
        $methodParameters = $this->reflectionService
            ->getClassSchema(static::class)
            ->getMethod($this->actionMethodName)->getParameters();

        foreach ($methodParameters as $parameterName => $parameter) {
            $dataType = null;
            if ($parameter->getType() !== null) {
                $dataType = $parameter->getType();
            } elseif ($parameter->isArray()) {
                $dataType = 'array';
            }
            if ($dataType === null) {
                throw new InvalidArgumentTypeException('The argument type for parameter $' . $parameterName . ' of method ' . static::class . '->' . $this->actionMethodName . '() could not be detected.', 1253175643);
            }
            $defaultValue = $parameter->hasDefaultValue() ? $parameter->getDefaultValue() : null;
            $this->arguments->addNewArgument($parameterName, $dataType, !$parameter->isOptional(), $defaultValue);
        }
    }

    /**
     * Adds the needed validators to the Arguments:
     *
     * - Validators checking the data type from the @param annotation
     * - Custom validators specified with validate annotations.
     * - Model-based validators (validate annotations in the model)
     * - Custom model validator classes
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function initializeActionMethodValidators()
    {
        if ($this->arguments->count() === 0) {
            return;
        }

        $classSchemaMethod = $this->reflectionService->getClassSchema(static::class)
            ->getMethod($this->actionMethodName);

        /** @var \TYPO3\CMS\Extbase\Mvc\Controller\Argument $argument */
        foreach ($this->arguments as $argument) {
            $classSchemaMethodParameter = $classSchemaMethod->getParameter($argument->getName());
            /*
             * At this point validation is skipped if there is an IgnoreValidation annotation.
             *
             * todo: IgnoreValidation annotations could be evaluated in the ClassSchema and result in
             * todo: no validators being applied to the method parameter.
             */
            if ($classSchemaMethodParameter->ignoreValidation()) {
                continue;
            }

            // todo: It's quite odd that an instance of ConjunctionValidator is created directly here.
            // todo: \TYPO3\CMS\Extbase\Validation\ValidatorResolver::getBaseValidatorConjunction could/should be used
            // todo: here, to benefit of the built in 1st level cache of the ValidatorResolver.
            $validator = $this->objectManager->get(ConjunctionValidator::class);

            foreach ($classSchemaMethodParameter->getValidators() as $validatorDefinition) {
                /** @var ValidatorInterface $validatorInstance */
                $validatorInstance = $this->objectManager->get(
                    $validatorDefinition['className'],
                    $validatorDefinition['options']
                );

                $validator->addValidator(
                    $validatorInstance
                );
            }

            $baseValidatorConjunction = $this->validatorResolver->getBaseValidatorConjunction($argument->getDataType());
            if ($baseValidatorConjunction->count() > 0) {
                $validator->addValidator($baseValidatorConjunction);
            }
            $argument->setValidator($validator);
        }
    }

    /**
     * Collects the base validators which were defined for the data type of each
     * controller argument and adds them to the argument's validator chain.
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function initializeControllerArgumentsBaseValidators()
    {
        /** @var \TYPO3\CMS\Extbase\Mvc\Controller\Argument $argument */
        foreach ($this->arguments as $argument) {
            $validator = $this->validatorResolver->getBaseValidatorConjunction($argument->getDataType());
            if ($validator !== null) {
                $argument->setValidator($validator);
            }
        }
    }

    /**
     * Handles an incoming request and returns a response object
     *
     * @param \TYPO3\CMS\Extbase\Mvc\RequestInterface $request The request object
     * @return ResponseInterface
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function processRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        $this->request->setDispatched(true);
        $this->uriBuilder = $this->objectManager->get(UriBuilder::class);
        $this->uriBuilder->setRequest($request);
        $this->actionMethodName = $this->resolveActionMethodName();
        $this->initializeActionMethodArguments();
        $this->initializeActionMethodValidators();
        $this->mvcPropertyMappingConfigurationService->initializePropertyMappingConfigurationFromRequest($request, $this->arguments);
        $this->initializeAction();
        $actionInitializationMethodName = 'initialize' . ucfirst($this->actionMethodName);
        /** @var callable $callable */
        $callable = [$this, $actionInitializationMethodName];
        if (method_exists($this, $actionInitializationMethodName)) {
            // todo: replace method_exists with is_callable or even both
            //       method_exists alone does not guarantee that $callable is actually callable
            call_user_func($callable);
        }
        $this->mapRequestArgumentsToControllerArguments();
        $this->controllerContext = $this->buildControllerContext();
        $this->view = $this->resolveView();
        if ($this->view !== null) {
            $this->initializeView($this->view);
        }
        $response = $this->callActionMethod($request);
        $this->renderAssetsForRequest($request);

        return $response;
    }

    /**
     * Method which initializes assets that should be attached to the response
     * for the given $request, which contains parameters that an override can
     * use to determine which assets to add via PageRenderer.
     *
     * This default implementation will attempt to render the sections "HeaderAssets"
     * and "FooterAssets" from the template that is being rendered, inserting the
     * rendered content into either page header or footer, as appropriate. Both
     * sections are optional and can be used one or both in combination.
     *
     * You can add assets with this method without worrying about duplicates, if
     * for example you do this in a plugin that gets used multiple time on a page.
     *
     * @param \TYPO3\CMS\Extbase\Mvc\RequestInterface $request
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function renderAssetsForRequest($request)
    {
        if (!$this->view instanceof TemplateView) {
            // Only TemplateView (from Fluid engine, so this includes all TYPO3 Views based
            // on TYPO3's AbstractTemplateView) supports renderSection(). The method is not
            // declared on ViewInterface - so we must assert a specific class. We silently skip
            // asset processing if the View doesn't match, so we don't risk breaking custom Views.
            return;
        }
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $variables = ['request' => $request, 'arguments' => $this->arguments];
        $headerAssets = $this->view->renderSection('HeaderAssets', $variables, true);
        $footerAssets = $this->view->renderSection('FooterAssets', $variables, true);
        if (!empty(trim($headerAssets))) {
            $pageRenderer->addHeaderData($headerAssets);
        }
        if (!empty(trim($footerAssets))) {
            $pageRenderer->addFooterData($footerAssets);
        }
    }

    /**
     * Resolves and checks the current action method name
     *
     * @return string Method name of the current action
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\NoSuchActionException if the action specified in the request object does not exist (and if there's no default action either).
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function resolveActionMethodName()
    {
        $actionMethodName = $this->request->getControllerActionName() . 'Action';
        if (!method_exists($this, $actionMethodName)) {
            throw new NoSuchActionException('An action "' . $actionMethodName . '" does not exist in controller "' . static::class . '".', 1186669086);
        }
        return $actionMethodName;
    }

    /**
     * Calls the specified action method and passes the arguments.
     *
     * If the action returns a string, it is appended to the content in the
     * response object. If the action doesn't return anything and a valid
     * view exists, the view is rendered automatically.
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function callActionMethod(RequestInterface $request): ResponseInterface
    {
        // incoming request is not needed yet but can be passed into the action in the future like in symfony
        // todo: support this via method-reflection

        $preparedArguments = [];
        /** @var \TYPO3\CMS\Extbase\Mvc\Controller\Argument $argument */
        foreach ($this->arguments as $argument) {
            $preparedArguments[] = $argument->getValue();
        }
        $validationResult = $this->arguments->validate();
        if (!$validationResult->hasErrors()) {
            $this->eventDispatcher->dispatch(new BeforeActionCallEvent(static::class, $this->actionMethodName, $preparedArguments));
            $actionResult = $this->{$this->actionMethodName}(...$preparedArguments);
        } else {
            $actionResult = $this->{$this->errorMethodName}();
        }

        if ($actionResult instanceof ResponseInterface) {
            return $actionResult;
        }

        trigger_error(
            sprintf(
                'Controller action %s does not return an instance of %s which is deprecated.',
                static::class . '::' . $this->actionMethodName,
                ResponseInterface::class
            ),
            E_USER_DEPRECATED
        );

        $response = new \TYPO3\CMS\Core\Http\Response();
        $body = new Stream('php://temp', 'rw');
        if ($actionResult === null && $this->view instanceof ViewInterface) {
            if ($this->view instanceof JsonView) {
                // this is just a temporary solution until Extbase uses PSR-7 responses and users are forced to return a
                // response object in their controller actions.

                if (!empty($GLOBALS['TSFE']) && $GLOBALS['TSFE'] instanceof TypoScriptFrontendController) {
                    /** @var TypoScriptFrontendController $typoScriptFrontendController */
                    $typoScriptFrontendController = $GLOBALS['TSFE'];
                    if (empty($typoScriptFrontendController->config['config']['disableCharsetHeader'])) {
                        // If the charset header is *not* disabled in configuration,
                        // TypoScriptFrontendController will send the header later with the Content-Type which we set here.
                        $typoScriptFrontendController->setContentType('application/json');
                    } else {
                        // Although the charset header is disabled in configuration, we *must* send a Content-Type header here.
                        // Content-Type headers optionally carry charset information at the same time.
                        // Since we have the information about the charset, there is no reason to not include the charset information although disabled in TypoScript.
                        $response = $response->withHeader('Content-Type', 'application/json; charset=' . trim($typoScriptFrontendController->metaCharset));
                    }
                } else {
                    $response = $response->withHeader('Content-Type', 'application/json');
                }
            }

            $body->write($this->view->render());
        } elseif (is_string($actionResult) && $actionResult !== '') {
            $body->write($actionResult);
        } elseif (is_object($actionResult) && method_exists($actionResult, '__toString')) {
            $body->write((string)$actionResult);
        }

        $body->rewind();
        return $response->withBody($body);
    }

    /**
     * Prepares a view for the current action.
     * By default, this method tries to locate a view with a name matching the current action.
     *
     * @return ViewInterface
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function resolveView()
    {
        if ($this->viewResolver instanceof GenericViewResolver) {
            /*
             * This setter is not part of the ViewResolverInterface as it's only necessary to set
             * the default view class from this point when using the generic view resolver which
             * must respect the possibly overridden property defaultViewObjectName.
             */
            $this->viewResolver->setDefaultViewClass($this->defaultViewObjectName);
        }

        $view = $this->viewResolver->resolve(
            $this->request->getControllerObjectName(),
            $this->request->getControllerActionName(),
            $this->request->getFormat()
        );

        if ($view instanceof ViewInterface) {
            $this->setViewConfiguration($view);
            if ($view->canRender($this->controllerContext) === false) {
                $view = null;
            }
        }
        if (!isset($view)) {
            $view = $this->objectManager->get(NotFoundView::class);
            $view->assign('errorMessage', 'No template was found. View could not be resolved for action "'
                . $this->request->getControllerActionName() . '" in class "' . $this->request->getControllerObjectName() . '"');
        }
        $view->setControllerContext($this->controllerContext);
        if (method_exists($view, 'injectSettings')) {
            $view->injectSettings($this->settings);
        }
        $view->initializeView();
        // In TYPO3.Flow, solved through Object Lifecycle methods, we need to call it explicitly
        $view->assign('settings', $this->settings);
        // same with settings injection.
        return $view;
    }

    /**
     * @param ViewInterface $view
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function setViewConfiguration(ViewInterface $view)
    {
        // Template Path Override
        $extbaseFrameworkConfiguration = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK
        );

        // set TemplateRootPaths
        $viewFunctionName = 'setTemplateRootPaths';
        if (method_exists($view, $viewFunctionName)) {
            $setting = 'templateRootPaths';
            $parameter = $this->getViewProperty($extbaseFrameworkConfiguration, $setting);
            // no need to bother if there is nothing to set
            if ($parameter) {
                $view->$viewFunctionName($parameter);
            }
        }

        // set LayoutRootPaths
        $viewFunctionName = 'setLayoutRootPaths';
        if (method_exists($view, $viewFunctionName)) {
            $setting = 'layoutRootPaths';
            $parameter = $this->getViewProperty($extbaseFrameworkConfiguration, $setting);
            // no need to bother if there is nothing to set
            if ($parameter) {
                $view->$viewFunctionName($parameter);
            }
        }

        // set PartialRootPaths
        $viewFunctionName = 'setPartialRootPaths';
        if (method_exists($view, $viewFunctionName)) {
            $setting = 'partialRootPaths';
            $parameter = $this->getViewProperty($extbaseFrameworkConfiguration, $setting);
            // no need to bother if there is nothing to set
            if ($parameter) {
                $view->$viewFunctionName($parameter);
            }
        }
    }

    /**
     * Handles the path resolving for *rootPath(s)
     *
     * numerical arrays get ordered by key ascending
     *
     * @param array $extbaseFrameworkConfiguration
     * @param string $setting parameter name from TypoScript
     *
     * @return array
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function getViewProperty($extbaseFrameworkConfiguration, $setting)
    {
        $values = [];
        if (
            !empty($extbaseFrameworkConfiguration['view'][$setting])
            && is_array($extbaseFrameworkConfiguration['view'][$setting])
        ) {
            $values = $extbaseFrameworkConfiguration['view'][$setting];
        }

        return $values;
    }

    /**
     * A special action which is called if the originally intended action could
     * not be called, for example if the arguments were not valid.
     *
     * The default implementation sets a flash message, request errors and forwards back
     * to the originating action. This is suitable for most actions dealing with form input.
     *
     * We clear the page cache by default on an error as well, as we need to make sure the
     * data is re-evaluated when the user changes something.
     *
     * @return ResponseInterface
     */
    protected function errorAction()
    {
        $this->clearCacheOnError();
        $this->addErrorFlashMessage();
        if (($response = $this->forwardToReferringRequest()) !== null) {
            return $response;
        }

        return $this->htmlResponse($this->getFlattenedValidationErrorMessage());
    }

    /**
     * Clear cache of current page on error. Needed because we want a re-evaluation of the data.
     * Better would be just do delete the cache for the error action, but that is not possible right now.
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function clearCacheOnError()
    {
        $extbaseSettings = $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);
        if (isset($extbaseSettings['persistence']['enableAutomaticCacheClearing']) && $extbaseSettings['persistence']['enableAutomaticCacheClearing'] === '1') {
            if (isset($GLOBALS['TSFE'])) {
                $pageUid = $GLOBALS['TSFE']->id;
                $this->cacheService->clearPageCache([$pageUid]);
            }
        }
    }

    /**
     * If an error occurred during this request, this adds a flash message describing the error to the flash
     * message container.
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function addErrorFlashMessage()
    {
        $errorFlashMessage = $this->getErrorFlashMessage();
        if ($errorFlashMessage !== false) {
            $this->addFlashMessage($errorFlashMessage, '', FlashMessage::ERROR);
        }
    }

    /**
     * A template method for displaying custom error flash messages, or to
     * display no flash message at all on errors. Override this to customize
     * the flash message in your action controller.
     *
     * @return string The flash message or FALSE if no flash message should be set
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function getErrorFlashMessage()
    {
        return 'An error occurred while trying to call ' . static::class . '->' . $this->actionMethodName . '()';
    }

    /**
     * If information on the request before the current request was sent, this method forwards back
     * to the originating request. This effectively ends processing of the current request, so do not
     * call this method before you have finished the necessary business logic!
     *
     * @return ResponseInterface|null
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function forwardToReferringRequest(): ?ResponseInterface
    {
        $referringRequest = null;
        $referringRequestArguments = $this->request->getInternalArguments()['__referrer'] ?? null;
        if (is_string($referringRequestArguments['@request'] ?? null)) {
            $referrerArray = json_decode(
                $this->hashService->validateAndStripHmac($referringRequestArguments['@request']),
                true
            );
            $arguments = [];
            if (is_string($referringRequestArguments['arguments'] ?? null)) {
                $arguments = unserialize(
                    base64_decode($this->hashService->validateAndStripHmac($referringRequestArguments['arguments']))
                );
            }
            // todo: Remove ReferringRequest. It's only used here in this context to trigger the logic of
            //       \TYPO3\CMS\Extbase\Mvc\Web\ReferringRequest::setArgument() and its parent method which should then
            //       be extracted from the request class.
            $referringRequest = new ReferringRequest();
            $referringRequest->setArguments(array_replace_recursive($arguments, $referrerArray));
        }

        if ($referringRequest !== null) {
            return (new ForwardResponse((string)$referringRequest->getControllerActionName()))
                ->withControllerName((string)$referringRequest->getControllerName())
                ->withExtensionName((string)$referringRequest->getControllerExtensionName())
                ->withArguments($referringRequest->getArguments())
                ->withArgumentsValidationResult($this->arguments->validate())
            ;
        }

        return null;
    }

    /**
     * Returns a string with a basic error message about validation failure.
     * We may add all validation error messages to a log file in the future,
     * but for security reasons (@see #54074) we do not return these here.
     *
     * @return string
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function getFlattenedValidationErrorMessage()
    {
        $outputMessage = 'Validation failed while trying to call ' . static::class . '->' . $this->actionMethodName . '().' . PHP_EOL;
        return $outputMessage;
    }

    /**
     * @return ControllerContext
     */
    public function getControllerContext()
    {
        return $this->controllerContext;
    }

    /**
     * Creates a Message object and adds it to the FlashMessageQueue.
     *
     * @param string $messageBody The message
     * @param string $messageTitle Optional message title
     * @param int $severity Optional severity, must be one of \TYPO3\CMS\Core\Messaging\FlashMessage constants
     * @param bool $storeInSession Optional, defines whether the message should be stored in the session (default) or not
     * @throws \InvalidArgumentException if the message body is no string
     * @see \TYPO3\CMS\Core\Messaging\FlashMessage
     */
    public function addFlashMessage($messageBody, $messageTitle = '', $severity = AbstractMessage::OK, $storeInSession = true)
    {
        if (!is_string($messageBody)) {
            throw new \InvalidArgumentException('The message body must be of type string, "' . gettype($messageBody) . '" given.', 1243258395);
        }
        /* @var \TYPO3\CMS\Core\Messaging\FlashMessage $flashMessage */
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            (string)$messageBody,
            (string)$messageTitle,
            $severity,
            $storeInSession
        );

        $this->getFlashMessageQueue()->enqueue($flashMessage);
    }

    /**
     * todo: As soon as the incoming request contains the compiled plugin namespace, extbase will offer a trait to
     *       create a flash message identifier from the current request. Users then should inject the flash message
     *       service themselves if needed.
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function getFlashMessageQueue(string $identifier = null): FlashMessageQueue
    {
        if ($identifier === null) {
            $pluginNamespace = $this->internalExtensionService->getPluginNamespace(
                $this->request->getControllerExtensionName(),
                $this->request->getPluginName()
            );
            $identifier = 'extbase.flashmessages.' . $pluginNamespace;
        }

        return $this->internalFlashMessageService->getMessageQueueByIdentifier($identifier);
    }

    /**
     * Initialize the controller context
     *
     * @return \TYPO3\CMS\Extbase\Mvc\Controller\ControllerContext ControllerContext to be passed to the view
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function buildControllerContext()
    {
        /** @var \TYPO3\CMS\Extbase\Mvc\Controller\ControllerContext $controllerContext */
        $controllerContext = $this->objectManager->get(ControllerContext::class);
        $controllerContext->setRequest($this->request);
        if ($this->arguments !== null) {
            $controllerContext->setArguments($this->arguments);
        }
        $controllerContext->setUriBuilder($this->uriBuilder);

        return $controllerContext;
    }

    /**
     * Forwards the request to another action and / or controller.
     *
     * Request is directly transferred to the other action / controller
     * without the need for a new request.
     *
     * @param string $actionName Name of the action to forward to
     * @param string|null $controllerName Unqualified object name of the controller to forward to. If not specified, the current controller is used.
     * @param string|null $extensionName Name of the extension containing the controller to forward to. If not specified, the current extension is assumed.
     * @param array|null $arguments Arguments to pass to the target action
     * @throws StopActionException
     * @see redirect()
     * @deprecated since TYPO3 11.0, will be removed in 12.0
     */
    public function forward($actionName, $controllerName = null, $extensionName = null, array $arguments = null)
    {
        trigger_error(
            sprintf('Method %s is deprecated. To forward to another action, return a %s instead.', __METHOD__, ForwardResponse::class),
            E_USER_DEPRECATED
        );

        $this->request->setDispatched(false);
        $this->request->setControllerActionName($actionName);

        if ($controllerName !== null) {
            $this->request->setControllerName($controllerName);
        }

        if ($extensionName !== null) {
            $this->request->setControllerExtensionName($extensionName);
        }

        if ($arguments !== null) {
            $this->request->setArguments($arguments);
        }
        throw new StopActionException('forward', 1476045801);
    }

    /**
     * Redirects the request to another action and / or controller.
     *
     * Redirect will be sent to the client which then performs another request to the new URI.
     *
     * NOTE: This method only supports web requests and will thrown an exception
     * if used with other request types.
     *
     * @param string $actionName Name of the action to forward to
     * @param string|null $controllerName Unqualified object name of the controller to forward to. If not specified, the current controller is used.
     * @param string|null $extensionName Name of the extension containing the controller to forward to. If not specified, the current extension is assumed.
     * @param array|null $arguments Arguments to pass to the target action
     * @param int|null $pageUid Target page uid. If NULL, the current page uid is used
     * @param int $delay (optional) The delay in seconds. Default is no delay.
     * @param int $statusCode (optional) The HTTP status code for the redirect. Default is "303 See Other
     * @throws StopActionException
     */
    protected function redirect($actionName, $controllerName = null, $extensionName = null, array $arguments = null, $pageUid = null, $delay = 0, $statusCode = 303)
    {
        if ($controllerName === null) {
            $controllerName = $this->request->getControllerName();
        }
        $this->uriBuilder->reset()->setCreateAbsoluteUri(true);
        if (MathUtility::canBeInterpretedAsInteger($pageUid)) {
            $this->uriBuilder->setTargetPageUid((int)$pageUid);
        }
        if (GeneralUtility::getIndpEnv('TYPO3_SSL')) {
            $this->uriBuilder->setAbsoluteUriScheme('https');
        }
        $uri = $this->uriBuilder->uriFor($actionName, $arguments, $controllerName, $extensionName);
        $this->redirectToUri($uri, $delay, $statusCode);
    }

    /**
     * Redirects the web request to another uri.
     *
     * NOTE: This method only supports web requests and will thrown an exception if used with other request types.
     *
     * @param mixed $uri A string representation of a URI
     * @param int $delay (optional) The delay in seconds. Default is no delay.
     * @param int $statusCode (optional) The HTTP status code for the redirect. Default is "303 See Other
     * @throws StopActionException
     */
    protected function redirectToUri($uri, $delay = 0, $statusCode = 303)
    {
        $this->objectManager->get(CacheService::class)->clearCachesOfRegisteredPageIds();

        $uri = $this->addBaseUriIfNecessary($uri);
        $escapedUri = htmlentities($uri, ENT_QUOTES, 'utf-8');

        $response = new HtmlResponse(
            '<html><head><meta http-equiv="refresh" content="' . (int)$delay . ';url=' . $escapedUri . '"/></head></html>',
            $statusCode,
            [
                'Location' => (string)$uri
            ]
        );

        // Avoid caching the plugin when we issue a redirect response
        // This means that even when an action is configured as cachable
        // we avoid the plugin to be cached, but keep the page cache untouched
        $contentObject = $this->configurationManager->getContentObject();
        if ($contentObject->getUserObjectType() === ContentObjectRenderer::OBJECTTYPE_USER) {
            $contentObject->convertToUserIntObject();
        }

        throw new StopActionException('redirectToUri', 1476045828, null, $response);
    }

    /**
     * Adds the base uri if not already in place.
     *
     * @param string $uri The URI
     * @return string
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function addBaseUriIfNecessary($uri)
    {
        return GeneralUtility::locationHeaderUrl((string)$uri);
    }

    /**
     * Sends the specified HTTP status immediately.
     *
     * NOTE: This method only supports web requests and will thrown an exception if used with other request types.
     *
     * @param int $statusCode The HTTP status code
     * @param string $statusMessage A custom HTTP status message
     * @param string $content Body content which further explains the status
     * @throws StopActionException
     */
    public function throwStatus($statusCode, $statusMessage = null, $content = null)
    {
        if ($content === null) {
            $content = $statusCode . ' ' . $statusMessage;
        }

        $response = new \TYPO3\CMS\Core\Http\Response($content);

        throw new StopActionException('throwStatus', 1476045871, null, $response);
    }

    /**
     * Maps arguments delivered by the request object to the local controller arguments.
     *
     * @throws Exception\RequiredArgumentMissingException
     *
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    protected function mapRequestArgumentsToControllerArguments()
    {
        /** @var \TYPO3\CMS\Extbase\Mvc\Controller\Argument $argument */
        foreach ($this->arguments as $argument) {
            $argumentName = $argument->getName();
            if ($this->request->hasArgument($argumentName)) {
                $this->setArgumentValue($argument, $this->request->getArgument($argumentName));
            } elseif ($argument->isRequired()) {
                throw new RequiredArgumentMissingException('Required argument "' . $argumentName . '" is not set for ' . $this->request->getControllerObjectName() . '->' . $this->request->getControllerActionName() . '.', 1298012500);
            }
        }
    }

    /**
     * @param Argument $argument
     * @param mixed $rawValue
     */
    private function setArgumentValue(Argument $argument, $rawValue): void
    {
        if ($rawValue === null) {
            $argument->setValue(null);
            return;
        }
        $dataType = $argument->getDataType();
        if (is_object($rawValue) && $rawValue instanceof $dataType) {
            $argument->setValue($rawValue);
            return;
        }
        $this->propertyMapper->resetMessages();
        try {
            $argument->setValue(
                $this->propertyMapper->convert(
                    $rawValue,
                    $dataType,
                    $argument->getPropertyMappingConfiguration()
                )
            );
        } catch (TargetNotFoundException $e) {
            // for optional arguments no exception is thrown.
            if ($argument->isRequired()) {
                throw $e;
            }
        }
        $argument->getValidationResults()->merge($this->propertyMapper->getMessages());
    }

    /**
     * Returns a response object with either the given html string or the current rendered view as content.
     *
     * @param string|null $html
     * @return ResponseInterface
     */
    protected function htmlResponse(string $html = null): ResponseInterface
    {
        $response = $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write($html ?? $this->view->render());
        return $response;
    }
}
