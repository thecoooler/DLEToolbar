<?php

function echoheader(string $title, string $subtitle): void
{
    echo '<header>' . $title . $subtitle . '</header>';
}

function echofooter(): void
{
    echo '</footer>';
}
