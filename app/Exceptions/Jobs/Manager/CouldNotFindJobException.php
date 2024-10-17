<?php

namespace App\Exceptions\Jobs\Manager;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class CouldNotFindJobException extends Exception
{
    public static int $FROM_CONTROLLER = 110;
    public static string $PUBLIC_MESSAGE = 'Could not launch job';
    private ?string $jobClass = null;

    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @throws CouldNotFindJobException
     */
    public static function throwFromController(\Throwable $e)
    {
        $wrap = new self('Unable to start new job', CouldNotFindJobException::$FROM_CONTROLLER, $e);
        $wrap->jobClass = get_class($e);
        throw $wrap;
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): Response
    {
        $message = sprintf("%s %s", static::$PUBLIC_MESSAGE, $this->jobClass !== null ? $this->jobClass : '');

        return \response([
            'code'    => self::$FROM_CONTROLLER,
            'message' => $message,
        ], 500);
    }

    public function report(): void
    {
        \Log::error($this->getPrevious());
    }
}
