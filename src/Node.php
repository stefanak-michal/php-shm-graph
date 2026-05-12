<?php

namespace StefanakMichal\PhpShmGraph;

class Node {
    public function __construct(
        public readonly int $id,
        public array $labels = [],
        public array $properties = [],
        public array $incoming = [],
        public array $outgoing = []
    ) { }

    public function __serialize(): array
    {
        return [
            'id'         => $this->id,
            'labels'     => $this->labels,
            'properties' => $this->properties,
            'incoming'   => $this->incoming,
            'outgoing'   => $this->outgoing,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id         = $data['id'];
        $this->labels     = $data['labels'];
        $this->properties = $data['properties'];
        $this->incoming   = $data['incoming'];
        $this->outgoing   = $data['outgoing'];
    }
}
