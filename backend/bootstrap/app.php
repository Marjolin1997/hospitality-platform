<?php

use App\Http\Middleware\RequireBusinessPermission;
use App\Http\Middleware\ResolveBusinessContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php',api: __DIR__.'/../routes/api.php',commands: __DIR__.'/../routes/console.php',health: '/up')
    ->withMiddleware(function(Middleware $middleware):void{
        $middleware->statefulApi();
        $middleware->alias(['tenant'=>ResolveBusinessContext::class,'permission'=>RequireBusinessPermission::class]);
    })
    ->withExceptions(function(Exceptions $exceptions):void{
        $exceptions->shouldRenderJsonWhen(fn(Request $request,Throwable $e)=>$request->is('api/*')||$request->expectsJson());
        $exceptions->render(function(AuthenticationException $e,Request $request){if($request->is('api/*'))return response()->json(['message'=>'Unauthenticated.'],401);});
        $exceptions->render(function(ValidationException $e,Request $request){if($request->is('api/*'))return response()->json(['message'=>'Please review the highlighted fields.','errors'=>$e->errors()],422);});
        $exceptions->render(function(HttpExceptionInterface $e,Request $request){if($request->is('api/*'))return response()->json(['message'=>$e->getMessage()?:'Request failed.'],$e->getStatusCode());});
    })->create();
