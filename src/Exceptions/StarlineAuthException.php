<?php namespace StarlineApi\Exceptions;

/**
 * Thrown when any step of the SLID auth chain fails
 * (bad credentials, expired tokens, missing slnet cookie).
 *
 * @author Alexander Tischenko <http://alex-tisch.ru>
 */
class StarlineAuthException extends StarlineException
{
}