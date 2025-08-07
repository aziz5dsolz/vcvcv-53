<?php

     
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BacklogProject;
use App\Models\Vote;
use App\Helpers\LogConstants;
use Yajra\DataTables\Facades\DataTables;
use Carbon\Carbon;

class ProjectController extends Controller
{

    public function index(Request $request)
    {
        $url_backlog_id = $request->id;
        return view('admin.projects', compact('url_backlog_id'));
    }

    public function getProject(Request $request)
    {
        if ($request->ajax()) {
            $url_backlog_id = $request->url_backlog_id;
            $showDeletedBacklogs = $request->showDeletedBacklogs;

            // Base query
            $query = BacklogProject::with(['User', 'Backlog'])
                ->withCount([
                    'votes as total_votes',
                    'votes as upvotes' => function ($query) {
                        $query->where('vote_type', 'up');
                    },
                    'votes as downvotes' => function ($query) {
                        $query->where('vote_type', 'down');
                    }
                ]);

            // Apply backlog filter
            if ($url_backlog_id) {
                $query->where('backlog_id', $url_backlog_id);
            }

            // Apply status filter based on showDeletedBacklogs
            if ($showDeletedBacklogs == 'true') {
                $query->where('status', '2'); // Only rejected projects
            } else {
                $query->whereIn('status', ['0', '1', '3']); // All except rejected
            }

            return DataTables::of($query)
                ->addIndexColumn()
                ->addColumn('project_id', function ($row) {
                    return $row->id;
                })
                ->addColumn('project_title', function ($row) {
                    return $row->title;
                })
                ->addColumn('backlog_id', function ($row) {
                    return $row->backlog->id ?? 'N/A';
                })
                ->addColumn('status_badge', function ($row) {
                    $badges = [
                        '0' => '<span class="badge bg-secondary">Pending</span>',
                        '1' => '<span class="badge bg-primary">Approved</span>',
                        '2' => '<span class="badge bg-danger">Rejected</span>',
                        '3' => '<span class="badge bg-success">Completed</span>'
                    ];
                    return $badges[$row->status] ?? '<span class="badge bg-secondary">Unknown</span>';
                })
                ->addColumn('uploaded_by', function ($row) {
                    return ($row->user->first_name ?? '') . ' ' . ($row->user->last_name ?? '');
                })
                ->addColumn('votes_clickable', function ($row) {
                    return '<span class="view-voter-list" data-id="' . $row->id . '" title="View Project List" style="cursor: pointer; color: green; font-weight: bold; text-decoration: underline;">' . $row->total_votes . '</span>';
                })
                ->addColumn('upvotes_clickable', function ($row) {
                    return '<span class="view-voter-list" data-id="' . $row->id . '" data-vote-type="up" title="View Upvoters" style="cursor: pointer; color: #28a745; font-weight: bold; text-decoration: underline;">👍 ' . ($row->upvotes ?? 0) . '</span>';
                })
                ->addColumn('downvotes_clickable', function ($row) {
                    return '<span class="view-voter-list" data-id="' . $row->id . '" data-vote-type="down" title="View Downvoters" style="cursor: pointer; color: #dc3545; font-weight: bold; text-decoration: underline;">👎 ' . ($row->downvotes ?? 0) . '</span>';
                })
                ->addColumn('git_url', function ($row) {
                    return $row->git_url;
                })
                ->addColumn('created_at_formatted', function ($row) {
                    return $row->created_at ? Carbon::parse($row->created_at)->format('d M Y') : 'N/A';
                })
                ->addColumn('actions', function ($row) {
                    $actions = '<div class="dropdown">
                        <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Actions
                        </button>
                        <ul class="dropdown-menu">';
                    
                    if ($row->status == '0') {
                        $actions .= '<li><a class="dropdown-item project-status" data-submission="' . ($row->user->first_name ?? '') . ' ' . ($row->user->last_name ?? '') . '" data-title="' . $row->title . '" data-status="1" data-id="' . $row->id . '">Approved</a></li>';
                    }
                    
                    if ($row->status == '1') {
                        $actions .= '<li><a class="dropdown-item project-status" data-status="2" data-id="' . $row->id . '">Rejected</a></li>';
                    }
                    
                    $actions .= '<li><a class="dropdown-item view-project" data-id="' . $row->id . '">View</a></li>
                        <li><a data-id="' . ($row->backlog->id ?? '') . '" class="dropdown-item view-history-log">View Log</a></li>
                        </ul>
                    </div>';
                    
                    return $actions;
                })
                ->filter(function ($query) use ($request) {
                    // Global search
                    if ($request->has('search') && $request->search['value']) {
                        $search = $request->search['value'];
                        $query->where(function ($q) use ($search) {
                            $q->where('backlog_projects.title', 'LIKE', "%$search%")
                              ->orWhere('backlog_projects.id', 'LIKE', "%$search%")
                              ->orWhere('backlog_projects.backlog_id', 'LIKE', "%$search%")
                              ->orWhere('backlog_projects.git_url', 'LIKE', "%$search%")
                              ->orWhereHas('User', function ($query) use ($search) {
                                  $query->where('first_name', 'LIKE', "%$search%")
                                        ->orWhere('last_name', 'LIKE', "%$search%");
                              });
                        });
                    }

                    // Status filter
                    if ($request->has('status_filter') && $request->status_filter != '') {
                        $query->where('status', $request->status_filter);
                    }

                    // Date range filter
                    if ($request->has('start_date') && $request->has('end_date') && 
                        $request->start_date && $request->end_date) {
                        $startDate = $request->start_date . ' 00:00:00';
                        $endDate = $request->end_date . ' 23:59:59';
                        $query->whereBetween('created_at', [$startDate, $endDate]);
                    }
                })
                ->order(function ($query) use ($request) {
                    // Default order by total votes DESC
                    if (!$request->has('order')) {
                        $query->orderBy('total_votes', 'DESC');
                    }
                })
                ->rawColumns(['status_badge', 'votes_clickable', 'upvotes_clickable', 'downvotes_clickable', 'actions'])
                ->make(true);
        }

        // For non-AJAX requests, return counts for counters
        $totalBackLogs = BacklogProject::count();
        $pendingBackLogs = BacklogProject::where('status', '0')->count();
        $approvedBackLogs = BacklogProject::where('status', '1')->count();
        $rejectedBackLogs = BacklogProject::where('status', '2')->count();

        return response()->json([
            'status' => 200,
            'data' => [
                'total_project' => $totalBackLogs,
                'pending_project' => $pendingBackLogs,
                'rejected_project' => $rejectedBackLogs,
                'approved_project' => $approvedBackLogs,
            ]
        ]);
    }

    public function getProjectCounts(Request $request)
    {
        $url_backlog_id = $request->url_backlog_id;
        
        $query = BacklogProject::query();
        if ($url_backlog_id) {
            $query->where('backlog_id', $url_backlog_id);
        }

        $totalBackLogs = (clone $query)->count();
        $pendingBackLogs = (clone $query)->where('status', '0')->count();
        $approvedBackLogs = (clone $query)->where('status', '1')->count();
        $rejectedBackLogs = (clone $query)->where('status', '2')->count();

        return response()->json([
            'status' => 200,
            'data' => [
                'total_project' => $totalBackLogs,
                'pending_project' => $pendingBackLogs,
                'rejected_project' => $rejectedBackLogs,
                'approved_project' => $approvedBackLogs,
            ]
        ]);
    }

    public function statusProject(Request $request)
    {
        $id = $request->id;
        $status = $request->status;

        // Update the project status
        BacklogProject::where('id', $id)->update(['status' => $status]);

        $pendingCount = BacklogProject::where('status', '0')->count();

        // Log the action
        logAction(
            LogConstants::Rejected, // Action type
            LogConstants::PROJECT,
            $id, // Entity ID (the backlog's ID)
            "Project id '{$id}' was rejected by admin."
        );

        // Return additional information for frontend handling
        return response()->json([
            'status' => 200,
            'message' => 'Status Change Successfully',
            'pending_count' => $pendingCount,
            'project_id' => $id,
            'project_status' => $status,
            'action' => $status == '2' ? 'rejected' : 'status_changed'
        ]);
    }

    public function confirmedApprovedProject(Request $request)
    {
        $validatedData = $request->validate([
            'approveVoteDeadline' => 'required',
        ]);

        $id = $request->project_id;
        $approveVoteDeadline = $request->approveVoteDeadline;

        BacklogProject::where('id', $id)->update(['status' => '1', 'vote_deadline' => $approveVoteDeadline]);

        logAction(
            LogConstants::Approved, // Action type
            LogConstants::PROJECT,
            $id, // Entity ID (the backlog's ID)
            "Project id '{$id}' was approved by admin."
        );

        return response()->json(['status' => 200, 'message' => 'Project Approved Successfully']);
    }

    public function viewProject(Request $request)
    {
        $id = $request->id;
        $records = BacklogProject::with(['Backlog', 'User'])->withCount([
            'votes as total_votes',
            'votes as upvotes' => function ($query) {
                $query->where('vote_type', 'up');
            },
            'votes as downvotes' => function ($query) {
                $query->where('vote_type', 'down');
            }
        ])->where('id', $id)->first();

        return response()->json(['status' => 200, 'records' => $records]);
    }

    public function viewVoterList(Request $request)
    {
        $project_id = $request->project_id;
        $vote_type = $request->vote_type; // Get the vote type filter

        $query = Vote::leftJoin('users', function ($join) {
            $join->on('votes.user_id', '=', 'users.id')
                ->where('votes.vote_mode', '!=', 'anonymous');
        })
            ->where('votes.project_id', $project_id);

        // Apply vote type filter if provided
        if ($vote_type) {
            $query->where('votes.vote_type', $vote_type);
        }

        $records = $query->select('votes.*', 'users.first_name as user_name', 'users.last_name as last_name')
            ->get();

        return response()->json(['status' => 200, 'records' => $records]);
    }
}
