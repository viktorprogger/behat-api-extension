<?php

declare(strict_types=1);

namespace Imbo\BehatApiExtension\Context\Initializer;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\Initializer\ContextInitializer;
use Imbo\BehatApiExtension\ArrayContainsComparator as Comparator;
use Imbo\BehatApiExtension\ArrayContainsComparator\Matcher;
use Imbo\BehatApiExtension\Context\ArrayContainsComparatorAwareContext;

/**
 * Array contains comparator context aware initializer
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 */
class ArrayContainsComparatorAwareInitializer implements ContextInitializer
{
    /**
     * @var Comparator
     */
    private Comparator $comparator;

    /**
     * Class constructor
     *
     * @param Comparator $comparator
     */
    public function __construct(Comparator $comparator)
    {
        $comparator
            ->addFunction('arrayLength', new Matcher\ArrayLength())
            ->addFunction('arrayMinLength', new Matcher\ArrayMinLength())
            ->addFunction('arrayMaxLength', new Matcher\ArrayMaxLength())
            ->addFunction('variableType', new Matcher\VariableType())
            ->addFunction('regExp', new Matcher\RegExp())
            ->addFunction('gt', new Matcher\GreaterThan())
            ->addFunction('lt', new Matcher\LessThan())
            ->addFunction('jwt', new Matcher\JWT($comparator));

        $this->comparator = $comparator;
    }

    /**
     * Initialize the context
     *
     * @param Context $context
     */
    public function initializeContext(Context $context): void
    {
        if ($context instanceof ArrayContainsComparatorAwareContext) {
            $context->setArrayContainsComparator($this->comparator);
        }
    }
}
