<?php

/**
 * Copyright 2017 Cloud Creativity Limited
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CloudCreativity\LaravelJsonApi\Routing;

use CloudCreativity\LaravelJsonApi\TestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class ResourceRegistrarTest
 *
 * @package CloudCreativity\LaravelJsonApi
 */
class ResourceRegistrarTest extends TestCase
{

    /**
     * @var Router
     */
    private $router;

    /**
     * @var ResourceRegistrar
     */
    private $registrar;

    protected function setUp()
    {
        /** @var Dispatcher $events */
        $events = $this->getMockBuilder(Dispatcher::class)->getMock();
        $this->router = new Router($events);
        $this->registrar = new ResourceRegistrar($this->router);
    }

    public function testBasic()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts');
        });

        $this->seeResource();
    }

    public function testHasOne()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts', [
                'has-one' => 'author',
            ]);
        });

        $this->seeHasOne('author');
    }

    public function testMultipleHasOne()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts', [
                'has-one' => ['author', 'site'],
            ]);
        });

        $this->seeHasOne('author');
        $this->seeHasOne('site');
    }

    public function testDasherizedHasOne()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts', [
                'has-one' => ['last-comment'],
            ]);
        });

        $this->seeHasOne('last-comment');
    }

    public function testHasMany()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts', [
                'has-many' => 'comments',
            ]);
        });

        $this->seeHasMany('comments');
    }

    public function testMultipleHasMany()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts', [
                'has-many' => ['comments', 'tags'],
            ]);
        });

        $this->seeHasMany('comments');
        $this->seeHasMany('tags');
    }

    public function testDasherizedHasMany()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts', [
                'has-many' => 'recent-comments',
            ]);
        });

        $this->seeHasMany('recent-comments');
    }

    public function testAllRelationships()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts', [
                'has-one' => 'author',
                'has-many' => ['comments', 'tags'],
            ]);
        });

        $this->seeHasOne('author');
        $this->seeHasMany('comments');
        $this->seeHasMany('tags');
    }

    public function testNotARelationship()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts', [
                'has-one' => 'author',
                'has-many' => ['comments', 'tags'],
            ]);
        });

        $this->seeNotFound(Request::create("/posts/123/site"));
        $this->seeNotFound(Request::create("/posts/123/relationships/site"));
        $this->seeNotFound(Request::create("/posts/123/relationships/site", 'PATCH'));
        $this->seeNotFound(Request::create("/posts/123/relationships/site", 'POST'));
        $this->seeNotFound(Request::create("/posts/123/relationships/site", 'DELETE'));
    }

    public function testSpecifiedController()
    {
        $this->registrar->resource('posts', [
            'controller' => PostsController::class,
            'has-one' => 'author',
            'has-many' => 'comments',
        ]);

        $this->seeResource();
        $this->seeHasOne('author');
        $this->seeHasMany('comments');
    }

    public function testSpecifiedAuthorizer()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts', [
                'authorizer' => 'App\JsonApi\GenericAuthorizer',
            ]);
        });

        $this->seeAuthorizer('App\JsonApi\GenericAuthorizer');
    }

    public function testSpecifiedValidators()
    {
        $this->router->group(['namespace' => __NAMESPACE__], function () {
            $this->registrar->resource('posts', [
                'validators' => 'App\JsonApi\GenericValidator',
            ]);
        });

        $this->seeValidator('App\JsonApi\GenericValidator');
    }

    /**
     * @param Request $request
     * @param string $content
     * @return Route
     */
    private function seeResponse(Request $request, $content)
    {
        $route = $this->router->getRoutes()->match($request);
        $this->assertEquals($content, $route->bind($request)->run());
        $this->assertEquals('posts', $route->parameter('resource_type'), 'resource type');

        return $route;
    }

    /**
     * @return void
     */
    private function seeResource()
    {
        $this->seeResponse(Request::create('/posts'), 'index');
        $this->seeResponse(Request::create('/posts', 'POST'), 'create');
        $this->seeResponse(Request::create('/posts/123'), 'read:123');
        $this->seeResponse(Request::create('/posts/123', 'PATCH'), 'update:123');
        $this->seeResponse(Request::create('/posts/123', 'DELETE'), 'delete:123');
    }

    /**
     * @param $relationship
     */
    private function seeHasOne($relationship)
    {
        $this->seeResponse(Request::create("/posts/123/$relationship"), "read-related:123:$relationship");
        $this->seeResponse(Request::create("/posts/123/relationships/$relationship"), "read-relationship:123:$relationship");
        $this->seeResponse(Request::create("/posts/123/relationships/$relationship", 'PATCH'), "replace-relationship:123:$relationship");

        /** These should not be registered (i.e. ensure it has not been registered as a has-many */
        $this->seeMethodNotAllowed(Request::create("/posts/123/relationships/$relationship", 'POST'), 'add-to relationship');
        $this->seeMethodNotAllowed(Request::create("/posts/123/relationships/$relationship", 'DELETE'), 'remove-from relationship');
    }

    /**
     * @param $relationship
     */
    private function seeHasMany($relationship)
    {
        $this->seeResponse(Request::create("/posts/123/$relationship"), "read-related:123:$relationship");
        $this->seeResponse(Request::create("/posts/123/relationships/$relationship"), "read-relationship:123:$relationship");
        $this->seeResponse(Request::create("/posts/123/relationships/$relationship", 'PATCH'), "replace-relationship:123:$relationship");
        $this->seeResponse(Request::create("/posts/123/relationships/$relationship", 'POST'), "add-relationship:123:$relationship");
        $this->seeResponse(Request::create("/posts/123/relationships/$relationship", 'DELETE'), "remove-relationship:123:$relationship");
    }

    /**
     * @param $expected
     */
    private function seeAuthorizer($expected)
    {
        $route = $this->seeResponse(Request::create('/posts'), 'index');
        $this->seeMiddleware($route, "json-api.authorize:$expected");
    }

    /**
     * @param $expected
     */
    private function seeValidator($expected)
    {
        $route = $this->seeResponse(Request::create('/posts'), 'index');
        $this->seeMiddleware($route, "json-api.validate:$expected");
    }

    /**
     * @param Route $route
     * @param $expected
     */
    private function seeMiddleware(Route $route, $expected)
    {
        $this->assertContains($expected, $route->middleware());
    }

    /**
     * @param Request $request
     * @param string|null $message
     */
    private function seeNotFound(Request $request, $message = null)
    {
        $notFound = false;

        try {
            $this->router->getRoutes()->match($request);
        } catch (NotFoundHttpException $ex) {
            $notFound = true;
        }

        $this->assertTrue($notFound, $message ?: 'Route was found');
    }

    /**
     * @param Request $request
     * @param string|null $message
     */
    private function seeMethodNotAllowed(Request $request, $message = null)
    {
        $notAllowed = false;

        try {
            $this->router->getRoutes()->match($request);
        } catch (MethodNotAllowedHttpException $ex) {
            $notAllowed = true;
        }

        $this->assertTrue($notAllowed, $message ?: 'Route was found');
    }
}
