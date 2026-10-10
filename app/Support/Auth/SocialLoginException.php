<?php

namespace App\Support\Auth;

use RuntimeException;

// An expected way for a Google / Apple sign-in to fail (cancelled, bad state, unverified email...). The
// message is the translation key shown on the login page; anything else thrown is a bug and gets reported.
class SocialLoginException extends RuntimeException {}
