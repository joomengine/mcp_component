<?php
// Fixed test subprocess. Requests select only bounded fixture behaviours, never PHP or a shell.
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
if (($input['mode'] ?? '') === 'timeout')
{
	sleep(5);
}
elseif (($input['mode'] ?? '') === 'oversize')
{
	fwrite(STDOUT, str_repeat('x', 10000));
	exit(0);
}
elseif (($input['mode'] ?? '') === 'failure')
{
	fwrite(STDERR, 'private-test-secret');
	exit(1);
}
echo json_encode(['protocol' => 'joomengine-worker/1', 'input' => $input, 'jcbEnv' => getenv('JCB_GET_ITEMS'), 'tokenEnv' => getenv('JOOMENGINE_MCP_TOKEN')], JSON_THROW_ON_ERROR);
