<?php
declare(strict_types=1);
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace Rabbit\DB;

/**
 * Class ExpressionBuilder builds objects of [[rabbit\db\Expression]] class.
 *
 * @author Dmitry Naumenko <d.naumenko.a@gmail.com>
 * @since 2.0.14
 */
class ExpressionBuilder implements ExpressionBuilderInterface
{
    use ExpressionBuilderTrait;


    /**
     * {@inheritdoc}
     * @param Expression|ExpressionInterface $expression the expression to be built
     */
    public function build(ExpressionInterface $expression, array &$params = []): string
    {
        $params = array_merge($params, $expression->params);
        return $expression->__toString();
    }
}
