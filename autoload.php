<?php

/**
 * Class map for Kirby's F::loadClasses(). Keys are lowercase class names,
 * values are paths relative to the plugin root. tests/AutoloadTest.php checks the
 * map against src/.
 */

return [
	'sayamaapps\\uketsuke\\auth' => 'src/Auth.php',
	'sayamaapps\\uketsuke\\hooks' => 'src/Hooks.php',
	'sayamaapps\\uketsuke\\ledger' => 'src/Ledger.php',
	'sayamaapps\\uketsuke\\notification' => 'src/Notification.php',
	'sayamaapps\\uketsuke\\notifications' => 'src/Notifications.php',
	'sayamaapps\\uketsuke\\options' => 'src/Options.php',
	'sayamaapps\\uketsuke\\payload' => 'src/Payload.php',
	'sayamaapps\\uketsuke\\protocol' => 'src/Protocol.php',
	'sayamaapps\\uketsuke\\purchase' => 'src/Purchase.php',
	'sayamaapps\\uketsuke\\receiver' => 'src/Receiver.php',
	'sayamaapps\\uketsuke\\reply' => 'src/Reply.php',
	'sayamaapps\\uketsuke\\retry' => 'src/Retry.php',
	'sayamaapps\\uketsuke\\routes' => 'src/Routes.php',
	'sayamaapps\\uketsuke\\schema' => 'src/Schema.php',
	'sayamaapps\\uketsuke\\signature' => 'src/Signature.php',
	'sayamaapps\\uketsuke\\sql' => 'src/Sql.php',
	'sayamaapps\\uketsuke\\uketsuke' => 'src/Uketsuke.php',
];
