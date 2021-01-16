#!/bin/bash
#
uuid=`/bin/cat /proc/sys/kernel/random/uuid`

/sbin/cryptsetup luksUUID --uuid=$uuid $1 <<<'YES\n'
