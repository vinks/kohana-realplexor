## What is it?
The Kohana 3.2 api library for [Dklab Realplexor](https://github.com/DmitryKoterov/dklab_realplexor): Comet server by Dmitry Koterov, dkLab (C) which handles 1000000+ parallel browser connections.

## Configuration
 1. Install [Dklab Realplexor](https://github.com/DmitryKoterov/dklab_realplexor)
 1. Plug-in kohana-realplexor module in bootstrap.php
 2. Tune *config/realplexor.php* default connection profile for your needs
 3. *Realplexor::instance()* returns realplexor api object with default profile. Certainly you can use an arbitrary profile name 'some-profile-name', that case use *Realplexor::instance('some-profile-name')*

## Usage
	$rpl = Realplexor::instance();

	// Send data to one channel.
	$rpl->send("Alpha", array("here" => "is", "any" => array("structured", "data")));

	// Send data to multiple channels at once.
	$rpl->send(array("Alpha", "Beta"), "any data");

	// Send data limiting receivers.
	$rpl->send("Alpha", "any data", array($id1, $id2, ...));

	// Send data with manual cursor specification (10 and 20).
	$rpl->send(array("Alpha" => 10, "Beta" => 20), "any data");

	// Get the list of all listened channels.
	$list = $rpl->online();

	// Get the list of online channels which names are started with "id_" only.
	$list = $rpl->online(array("id_"));

	// Watching status changes for channels 'id_***' from $pos"
	$pos = 0;
	while (1) {
    		foreach ($rpl->watch($pos, "id_") as $event) {
        		echo "Received: {$event['event']} - {$event['id']}\n";
        		$pos = $event['pos'];
    		}
    		usleep(300000);
	}
