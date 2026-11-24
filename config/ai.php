<?php
declare(strict_types=1);
return [
    'enabled'=>env('AI_ASSISTANT_ENABLED',false),
    'api_key'=>env_raw('OPENAI_API_KEY'),
    'model'=>env_raw('OPENAI_MODEL','gpt-6.1-sol'),
    'include_messages'=>env('AI_ASSISTANT_INCLUDE_MESSAGES',false),
];
