<?php

namespace nhbs\abtest;

use Yii;
use yii\base\Component;
use yii\base\Exception;
use yii\filters\AccessControl;
use yii\helpers\Inflector;
use yii\web\Cookie;

class Test extends Component
{
    public $filter;
    public $tests;

    public function prefix()
    {
        return 'yii2_split_test_';
    }

    public function key($v)
    {
        return $this->prefix() . Inflector::slug($v, '_');
    }

    public function value($name)
    {
        if(!$test = $this->getTest($name)){
            throw new Exception('Test "' . $name . '" is not in configuration');
        }

        $key = $this->key($name);

        if(!isset($test['values']) || !is_array($test['values'])){
            throw new Exception('Test "' . $name . '" requires an array of values');
        }
        $values = $test['values'];

        $filter = isset($test['filter']) ? $test['filter'] : $this->filter;
        if($filter){
            if(!isset($test['default'])){
                throw new Exception(
                    'Test "' . $name . '" requires a "default" parameter and value'
                );
            }

            // Add a default rule for allowing it all
            $filter['rules'][] = ['allow' => true];

            $o = Yii::createObject(
                array_merge(
                    [
                        'class' => AccessControl::class,
                    ],
                    $filter
                )
            );
            if(!$this->can($o)){
                $cookies = Yii::$app->response->cookies;
                $cookies->remove($key);
                return $test['default'];
            }
        }

        $cookies = Yii::$app->request->cookies;
        $active = false;
        if($cookies->has($key)) {
            $active = explode(';', $cookies->getValue($key))[1];
        }

        if(!$active || !in_array($active, $values)){
            // Pick a test randomly
            $active = $values[array_rand($values)];
            $cookies = Yii::$app->response->cookies;
            $cookie = new Cookie([
                'name' => $key,
                'value' => $name . ';' . $active,
                'expire' => 0
            ]);
            Yii::$app->getResponse()->getCookies()->add($cookie);
        }

        return $active;
    }

    public function listTests($allActions = false)
    {
        $cookies = Yii::$app->request->cookies;
        $list = [];
        foreach($this->tests as $k => $v){
            if(!isset($v['name'])){
                continue;
            }
            $name = $v['name'];

            $cookieTest = explode(';', $cookies[$this->key($name)]);
            if($cookieTest && is_array($cookieTest)){
                $test = $this->getTest($cookieTest[0]);
                unset($test['values']);

                if(!$allActions && isset($test['action'])){
                    $currentAction = Yii::$app->controller->id . '/'
                        . Yii::$app->controller->action->id;

                    if(
                        is_array($test['action']) &&
                        !in_array($currentAction, $test['action'])
                    ){
                        continue;
                    }elseif(
                        !is_array($test['action']) &&
                        $currentAction !== $test['action']
                    ){
                        continue;
                    }
                }

                if($test){
                    $list[$name] = array_merge(
                        $test,
                        [
                            'value' => $cookieTest[1]
                        ]
                    );
                }
            }
        }
        return $list;
    }

    public function getTest($name)
    {
        foreach($this->tests as $k => $v){
            if(isset($v['name']) && $v['name'] === $name){
                return $v;
            }
        }
    }

    public function can($ac)
    {
        $user = $ac->user;
        $request = Yii::$app->getRequest();
        /* @var $rule AccessRule */
        foreach($ac->rules as $rule){
            if($allow = $rule->allows(Yii::$app->controller->action, $user, $request)){
                return true;
            }elseif($allow === false){
                return false;
            }
        }
        return false;
    }
}
