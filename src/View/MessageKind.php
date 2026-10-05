<?php

declare(strict_types=1);

namespace NeuronTui\View;

/** @internal */
enum MessageKind
{
    case User;
    case Agent;
    case ToolCall;
    case ToolResult;
    case System;
    case Notice;
    case Error;
}
