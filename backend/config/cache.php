<?php
use Illuminate\Support\Str;
return ['default'=>env('CACHE_STORE','redis'),'stores'=>['array'=>['driver'=>'array','serialize'=>false],'database'=>['driver'=>'database','connection'=>env('DB_CACHE_CONNECTION'),'table'=>env('DB_CACHE_TABLE','cache'),'lock_connection'=>env('DB_CACHE_LOCK_CONNECTION'),'lock_table'=>env('DB_CACHE_LOCK_TABLE')],'redis'=>['driver'=>'redis','connection'=>env('CACHE_REDIS_CONNECTION','cache'),'lock_connection'=>env('CACHE_REDIS_LOCK_CONNECTION','default')]],'prefix'=>env('CACHE_PREFIX',Str::slug((string)env('APP_NAME','hospitality')).'-cache-')];
