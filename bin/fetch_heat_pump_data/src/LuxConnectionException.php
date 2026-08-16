<?php

namespace Luxtronic2;

/**
 * The heat pump could not be reached, or stopped answering.
 *
 * Expected and transient: heat pump powered down, wrong IP/port, controller web
 * server wedged. Log it, keep the previous retained MQTT message, try again on
 * the next cron run.
 */
class LuxConnectionException extends LuxException {
}
