<?php
return ['paths'=>['api/*','sanctum/csrf-cookie'],'allowed_methods'=>['*'],'allowed_origins'=>array_values(array_filter(explode(',',env('CORS_ALLOWED_ORIGINS','http://localhost:5173,http://localhost:8080')))),'allowed_origins_patterns'=>[],'allowed_headers'=>['*'],'exposed_headers'=>[],'max_age'=>0,'supports_credentials'=>true];
