<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

// На стендах exec и подобные могут быть запрещены в disable_functions,
// поэтому пакет обязан обходиться чтением файлов. В тестах git нужен
// для подготовки фикстур, отсюда ограничение только на неймспейс пакета.
arch('пакет не выполняет внешних команд')
    ->expect('GoCPA\\SpaceHealthcheck')
    ->not->toUse(['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open']);
