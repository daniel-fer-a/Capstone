<?php

return [
    'enabled'=>(bool)env('WORKSPACE_ENABLED',false),
    'invitation_hours'=>min(168,max(1,(int)env('WORKSPACE_INVITATION_HOURS',72))),
    'disk'=>'workspace-private',
];
