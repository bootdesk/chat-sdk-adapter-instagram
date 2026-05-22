<?php

declare(strict_types=1);

use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\Instagram\InstagramAdapter;

AdapterRegistry::register('instagram', InstagramAdapter::class);
