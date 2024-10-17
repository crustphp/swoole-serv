<?php

namespace App\Core\Enum;

/**
 * Enum ResponseStatusCode
 * 
 * This enum class contains constants for both WebSocket and HTTP status codes, 
 * making it easier to manage response codes.
 * 
 * You can add your own status codes in this Enum.
 */
enum ResponseStatusCode: int
{
    // WebSocket Status Codes
    // Reference: https://developer.mozilla.org/en-US/docs/Web/API/CloseEvent/code

    /**
     * The connection successfully completed the purpose for which it was created.
     */
    case CLOSE_NORMAL = 1000;

    /**
     * The endpoint is going away, either because of a server failure or the browser navigating away.
     */
    case CLOSE_GOING_AWAY = 1001;

    /**
     * The endpoint is terminating the connection due to a protocol error.
     */
    case CLOSE_PROTOCOL_ERROR = 1002;

    /**
     * The connection is being terminated because the endpoint received data of a type it cannot accept.
     */
    case CLOSE_UNSUPPORTED = 1003;

    /**
     * Indicates that the connection was closed abnormally without sending/receiving a Close frame.
     */
    case CLOSE_ABNORMAL = 1006;

    /**
     * The connection is being terminated because a message was received that contained inconsistent data.
     */
    case UNSUPPORTED_PAYLOAD = 1007;

    /**
     * The connection is being terminated because a data frame that is too large was received.
     */
    case CLOSE_TOO_LARGE = 1009;

    /**
     * The server is terminating the connection because it encountered an unexpected internal condition.
     */
    case SERVER_ERROR = 1011;

    /**
     * The server is terminating the connection because it is restarting.
     */
    case SERVICE_RESTART = 1012;

    /**
     * The server is terminating the connection due to a temporary condition (e.g., overload).
     */
    case TRY_AGAIN_LATER = 1013;


    // HTTP Status Codes
    // Reference: https://developer.mozilla.org/en-US/docs/Web/HTTP/Status

    /**
     * The request succeeded.
     */
    case OK = 200;

    /**
     * The request succeeded and resulted in the creation of a new resource.
     */
    case CREATED = 201;

    /**
     * The server cannot process the request due to a client error (e.g., malformed request syntax).
     */
    case BAD_REQUEST = 400;

    /**
     * The client must authenticate to get the requested response.
     */
    case UNAUTHORIZED = 401;

    /**
     * The client does not have access rights to the content.
     */
    case FORBIDDEN = 403;

    /**
     * The server cannot find the requested resource.
     */
    case NOT_FOUND = 404;

    /**
     * The method is not allowed for the requested resource.
     */
    case METHOD_NOT_ALLOWED = 405;

    /**
     * The server encountered an unexpected condition that prevented it from fulfilling the request.
     */
    case INTERNAL_SERVER_ERROR = 500;

    /**
     * The server is temporarily unable to handle the request (e.g., maintenance or overload).
     */
    case SERVICE_UNAVAILABLE = 503;
}
