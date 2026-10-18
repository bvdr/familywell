{{-- The sign-in / sign-up code email (App\Notifications\LoginCode). Frame, colours and the always-white rules live
     in mail/layout. --}}
@extends('mail.layout')

@section('content')
    <p style="margin:0 0 14px; font-family:'Geist Mono', 'SF Mono', Menlo, Consolas, monospace; font-size:12px; font-weight:500; letter-spacing:1px; text-transform:uppercase; color:#5E5B4E;">
        {{ $eyebrow }}
    </p>
    <h1 class="fw-ink" style="margin:0 0 12px; font-family:'Bricolage Grotesque', 'Helvetica Neue', Arial, sans-serif; font-weight:800; font-size:34px; line-height:1.1; letter-spacing:-1px; color:#121100; -webkit-text-fill-color:#121100;">
        {{ $heading }}
    </h1>
    <p class="fw-ink" style="margin:0 0 28px; font-family:Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:17px; line-height:1.55; color:#2A2918; -webkit-text-fill-color:#2A2918;">
        {{ $intro }}
    </p>

    {{-- The code, on the site's "sun" block: yellow with a 2px ink outline. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center" class="fw-sun" style="background:#FFDF00; background-image:linear-gradient(#FFDF00,#FFDF00); border:2px solid #121100; border-radius:20px; padding:22px 8px;">
                <span class="fw-ink" style="font-family:'Geist Mono', 'SF Mono', Menlo, Consolas, 'Courier New', monospace; font-size:44px; line-height:1; font-weight:700; letter-spacing:10px; color:#121100; -webkit-text-fill-color:#121100; padding-left:10px;">{{ $code }}</span>
            </td>
        </tr>
    </table>

    <p style="margin:22px 0 0; font-family:Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; line-height:1.55; color:#5E5B4E;">
        &#10003;&nbsp; {{ $expires }}
    </p>
@endsection

@section('footer')
    {{ $ignore }}
@endsection
