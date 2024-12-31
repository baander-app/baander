<?php

namespace App\Http\Controllers\Web;

use App\Auth\Webauthn\Actions\{
    FindPasskeyToAuthenticateAction,
    GeneratePasskeyAuthenticationOptionsAction,
    GeneratePasskeyRegisterOptionsAction,
    StorePasskeyAction
};
use App\Events\Auth\PasskeyUsedToAuthenticateEvent;
use App\Http\Controllers\Api\Auth\Concerns\HandlesUserTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\{AuthenticateUsingPasskeyRequest, StorePasskeyRequest};
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Log, Session};
use Spatie\RouteAttributes\Attributes\{Get, Post, Prefix};

/**
 * @tags Auth
 */
#[Prefix('/webauthn/passkey')]
class PasskeyController extends Controller
{
    use HandlesUserTokens;

    public const string REGISTER_OPTIONS_SESSION_KEY = 'passkey-registration-options';

    /**
     * Get a passkey challenge
     *
     * @unauthenticated
     *
     * @response array{
     *   challenge: string,
     *   rpId: string,
     *   allowCredentials: array{}
     * }
     */
    #[Get('/', 'auth.passkey.options')]
    public function getOptions(Request $request)
    {
        $action = new GeneratePasskeyAuthenticationOptionsAction();
        $options = $action->execute($request->user());

        return response()->json($options);
    }

    /**
     * Login with a passkey
     * @unauthenticated
     */
    #[Post('/', 'auth.passkey.login')]
    public function authenticate(AuthenticateUsingPasskeyRequest $request)
    {
        $findAuthenticatableUsingPasskey = new FindPasskeyToAuthenticateAction();
        $passkey = $findAuthenticatableUsingPasskey->execute(
            $request->get('start_authentication_response'),
            Session::get('passkey-authentication-options'),
        );

        if (!$passkey) {
            return $this->invalidPasskeyResponse();
        }

        $authenticatable = $passkey->user;

        if (!$authenticatable) {
            return $this->invalidPasskeyResponse();
        }

        $this->logInAuthenticatable($authenticatable);

        event(new PasskeyUsedToAuthenticateEvent($passkey));

        return $this->validPasskeyResponse($request, $authenticatable);
    }

    /**
     * Get passkey registration options
     */
    #[Get('/register', 'auth.passkey.register-options', ['auth:sanctum'])]
    public function getRegisterOptions(Request $request)
    {
        if (!$user = $request->user()) {
            abort(401, 'You must be logged in');
        }

        $action = new GeneratePasskeyRegisterOptionsAction();
        $options = $action->execute($user);

        session()->put('passkey-registration-options', $options);

        return $options;
    }

    /**
     * Register passkey
     */
    #[Post('/register', 'auth.passkey.register', ['auth:sanctum'])]
    public function registerPasskey(StorePasskeyRequest $request)
    {
        $action = new StorePasskeyAction();

        try {
            $action->execute(
                $request->user(),
                $request->get('passkey'),
                $this->previouslyGeneratedPasskeyOptions(),
                $request->host(),
                ['name' => $request->get('name')],
            );
        } catch (\Throwable $e) {
            Log::error('Could not store passkey', [
                'exception.message' => $e->getMessage(),
                'exception.code'    => $e->getCode(),
            ]);

            return response()->json(['error' => 'Could not store passkey'], 500);
        }

        return response()->json([
            'message' => 'Passkey successfully stored',
        ]);
    }

    public function logInAuthenticatable(Authenticatable $authenticatable): self
    {
        auth()->login($authenticatable);

        Session::regenerate();

        return $this;
    }

    public function validPasskeyResponse(Request $request, User $user)
    {
        $url = Session::has('passkeys.redirect')
            ? Session::pull('passkeys.redirect')
            : null;

        if ($url) {
            return redirect($url);
        }

        return $this->createTokenSet($request, $user);
    }

    protected function invalidPasskeyResponse()
    {
        return response()->json([
            'message' => __('passkeys::passkeys.invalid'),
        ])->setStatusCode(401);
    }

    protected function previouslyGeneratedPasskeyOptions(): ?string
    {
        return Session::pull(self::REGISTER_OPTIONS_SESSION_KEY);
    }
}
