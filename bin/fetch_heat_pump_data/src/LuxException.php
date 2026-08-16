<?php

namespace Luxtronic2;

/**
 * Base class for every error LuxController raises.
 *
 * LuxController deliberately does not let WebSocket\* exceptions escape: the
 * exception namespace changes completely in phrity/websocket 2.x, and fetch.php
 * should not have to know about that.
 */
class LuxException extends \RuntimeException {
}
