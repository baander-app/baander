@extends('auth.layout')

@section('content')
    <section>
        <h1 class="center">{{__('auth.2fa.title')}} - {{__('auth.2fa.confirm')}}</h1>
    </section>

    <article style="max-width: 600px;" class="mx-auto">
        <form>
            <input
                    type="text"
                    name="2fa_code"
                    aria-label="Authenticator code"
                    aria-describedby="email-helper"
            />
            <small id="email-helper">
                {{__('auth.2fa.continue')}}
            </small>

            <button type="submit">Confirm</button>
        </form>
    </article>
@endsection