<?php

namespace Luxtronic2;

/**
 * The heat pump answered, but not with anything we understand.
 *
 * Unexpected and sticky: a firmware that renamed or moved the "Informationen"
 * node, or a controller reporting its sections in another language. Retrying
 * will not help, so this is logged as critical.
 */
class LuxProtocolException extends LuxException {
}
