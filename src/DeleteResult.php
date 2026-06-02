<?php

declare(strict_types=1);

namespace Freespoke\Partner;

/**
 * Response data for a successful delete request.
 */
class DeleteResult
{
    public string $id = '';
    public string $error_message = '';
}
