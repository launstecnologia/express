# progresso.py — rastreamento de etapa atual por job (thread-safe)
from __future__ import annotations

import threading

_lock = threading.Lock()
_callbacks: dict[str, callable] = {}


def registrar(job_id: str, callback) -> None:
    with _lock:
        _callbacks[job_id] = callback


def remover(job_id: str) -> None:
    with _lock:
        _callbacks.pop(job_id, None)


def reportar(job_id: str | None, etapa: str) -> None:
    if not job_id:
        return
    with _lock:
        callback = _callbacks.get(job_id)
    if callback:
        callback(etapa)
