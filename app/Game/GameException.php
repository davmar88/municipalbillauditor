<?php

namespace App\Game;

use RuntimeException;

/**
 * Something a player tried that the rules don't allow. The message is shown
 * to the player as it is, so it says what to do instead.
 */
class GameException extends RuntimeException {}
