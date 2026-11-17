{{-- The student's course, year and section, e.g. "BSIT 4-B" (FR-ADM-10). --}}
@if ($code = $student->sectionCode())
    <span class="badge-neutral font-semibold" title="{{ $student->academicLabel() ?? 'Section' }}">{{ $code }}</span>
@else
    <span class="badge border border-dashed border-slate-300 font-normal text-slate-400" title="No course, year or section set yet">No section</span>
@endif
