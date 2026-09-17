<?php

/*
| scrapyard-linux is the native layer: its transports call ext-posi through
| the microscrap bindings. Nothing here loads the extension. What is proven
| locally is what stays pure PHP: device guards, fluent factory state, the
| transport a driver hands out for a registered handle, and sysfs PWM against
| a temp tree. Byte paths and edge events are proven on the Pi 5 over `fnk`.
*/
