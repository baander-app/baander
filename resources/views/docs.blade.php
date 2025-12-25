@extends('layouts.app')

@section('spa')
    @vite("resources/docs/index.tsx")
@endsection

@section('main')
    <div id="baanderdocs"></div>
@endsection