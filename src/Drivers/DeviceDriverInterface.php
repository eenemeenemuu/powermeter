<?php

interface DeviceDriverInterface
{
    /**
     * Query the device and return stats array.
     *
     * @return array Stats array with keys: date, time, power, [temp], [emeters]
     *               Or error array: ['error', 'message']
     */
    public function getStats(): array;

    /**
     * Return the device type identifier string.
     */
    public function getDeviceType(): string;
}

//EOF
