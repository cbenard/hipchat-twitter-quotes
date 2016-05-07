<?php

namespace cbenard;

use Respect\Validation\Exceptions\NestedValidationException;

class ConfigureValidation extends \DavidePastore\Slim\Validation\Validation
{
    protected $newValidators;
    protected $existingValidators;
    
    /**
     * Create new Validator service provider.
     *
     * @param null|array|ArrayAccess $validators
     * @param null|callable          $translator
     */
    public function __construct($newValidators, $existingValidators, $translator = null)
    {
        $this->newValidators = $newValidators;
        $this->existingValidators = $existingValidators;
        $this->translator = $translator;
    }

    /**
     * Validation middleware invokable class.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request  PSR7 request
     * @param \Psr\Http\Message\ResponseInterface      $response PSR7 response
     * @param callable                                 $next     Next middleware
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function __invoke($request, $response, $next)
    {
        $this->errors = [];

        $body = $request->getParsedBody();
        
        $saveID = null;
        foreach ($body as $key => $value) {
            if (strlen($key) > 5 && substr($key, 0, 5) == "save_") {
                $saveID = intval(substr($key, 5));
                break;
            }
        }
        
        $deleteID = null;
        foreach ($body as $key => $value) {
            if (strlen($key) > 7 && substr($key, 0, 7) == "delete_") {
                $deleteID = intval(substr($key, 7));
                break;
            }
        }

        if (isset($body['screen_name_new']) && $body['screen_name_new']) {
            // New configuration
            foreach ($this->newValidators as $key => $validator) {
                $param = $request->getParam($key);
                try {
                    $validator->assert($param);
                }
                catch (NestedValidationException $exception) {
                    if ($this->translator) {
                        $exception->setParam('translator', $this->translator);
                    }
                    $this->errors[$key] = $exception->getMessages();
                }
            }
        }
        elseif ($saveID !== null) {
            // Existing configuration (non-delete)
            foreach ($this->existingValidators as $key => $validators) {
                $validatorArray = is_array($validators) ? $validators : [ $validators ];
                $param = $request->getParam($key . strval($saveID));

                foreach ($validatorArray as $validator) {
                    try {
                        $validator->assert($param);
                    }
                    catch (NestedValidationException $exception) {
                        if ($this->translator) {
                            $exception->setParam('translator', $this->translator);
                        }
                        $this->errors[$key] = $exception->getMessages();
                    }
                }
            }
        }

        return $next($request, $response);
    }
}