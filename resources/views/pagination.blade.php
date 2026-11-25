@if ($paginator->hasPages())
<nav class="pagination" aria-label="Pagination">
    <span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
    @if ($paginator->previousPageUrl()) <a href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a> @endif
    @if ($paginator->nextPageUrl()) <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a> @endif
</nav>
@endif
