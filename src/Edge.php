<?php

namespace StefanakMichal\PhpShmGraph;

class Edge {
    public function __construct(
        public readonly int $id,
        public int $from,
        public int $to,
        public string $type,
        public array $properties = []
    ) { }

    public function __serialize(): array
    {
        return [
            'id'         => $this->id,
            'from'       => $this->from,
            'to'         => $this->to,
            'type'       => $this->type,
            'properties' => $this->properties,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id         = $data['id'];
        $this->from       = $data['from'];
        $this->to         = $data['to'];
        $this->type       = $data['type'];
        $this->properties = $data['properties'];
    }
}
