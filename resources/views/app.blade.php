@extends('layouts.app')

@section('spa')
    @vite("resources/app/index.tsx")
@endsection

@section('main')
    <div id="baanderapproot"></div>
@endsection