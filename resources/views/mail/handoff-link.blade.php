{{-- The onboarding's "email me a sign-in link" (App\Mail\HandoffLinkMail). Frame and colours live in mail/layout. --}}
@extends('mail.layout', ['subject' => 'Your FamilyWell sign-in link', 'preview' => 'Open it on your phone to carry on setting up FamilyWell.'])

@section('content')
    <p style="margin:0 0 14px; font-family:'Geist Mono', 'SF Mono', Menlo, Consolas, monospace; font-size:12px; font-weight:500; letter-spacing:1px; text-transform:uppercase; color:#5E5B4E;">
        Sign-in link
    </p>
    <h1 class="fw-ink" style="margin:0 0 12px; font-family:'Bricolage Grotesque', 'Helvetica Neue', Arial, sans-serif; font-weight:800; font-size:34px; line-height:1.1; letter-spacing:-1px; color:#121100; -webkit-text-fill-color:#121100;">
        Take it to your phone
    </h1>
    <p class="fw-ink" style="margin:0 0 28px; font-family:Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:17px; line-height:1.55; color:#2A2918; -webkit-text-fill-color:#2A2918;">
        Open this on your phone and you're signed in, right where you left off. It works once, for the next {{ $minutes }} minutes.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td class="fw-sun" style="background:#FFDF00; background-image:linear-gradient(#FFDF00,#FFDF00); border:2px solid #121100; border-radius:999px;">
                <a class="fw-ink" href="{{ $url }}" style="display:inline-block; padding:15px 26px; font-family:Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:16px; font-weight:700; color:#121100; -webkit-text-fill-color:#121100; text-decoration:none;">Open FamilyWell &rarr;</a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    Didn't ask for this? You can ignore it; nobody can use the link once it expires.
@endsection
