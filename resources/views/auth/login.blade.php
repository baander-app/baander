@extends('auth.layout')

@section('content')
    <section>
        <h1 class="center">Login</h1>
    </section>


    <article style="max-width: 600px;" class="mx-auto">
        <form method="POST" action="/login">
            <label>
                Email
                <input
                    type="email"
                    name="email"
                    placeholder="Email"
                    autocomplete="email"
                />
            </label>
            <input
                type="password"
                name="password"
                placeholder="Password"
                aria-label="Password"
                autocomplete="current-password"
            />

            <button type="submit">Login</button>
        </form>
    </article>
@endsection