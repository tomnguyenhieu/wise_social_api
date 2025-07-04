<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use DB;
use Illuminate\Http\Request;
use App\Models\Post;
use App\Models\User;
use App\Models\Like;
use App\Models\Comment;
use App\Models\Experience;
use App\Models\Skill;
use Google\Service\ShoppingContent\Resource\Pos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class TimeLineController extends Controller
{
	private $apiResponse;

	public function __construct()
	{
		$this->apiResponse = new ApiResponse();
	}

	public function timeline(Request $request)
	{
		$param = $request->all();
		$posts = Post::with([
			'author' => function ($authQuery) {
				return $authQuery->select('id', 'name', 'avatar', 'email', 'location');
			},
			'author.experiences' => function ($authExeQuery) {
				return $authExeQuery->orderBy('id', 'DESC')->get();
			},
			'author.skills' => function ($authSkillQuery) {
				return $authSkillQuery->select('id', 'skill', 'user_id');
			},
			'likes',
			'comments',
			'favorites' => function ($favoriteQuery) {
				return $favoriteQuery->where('user_id', Auth::user()->id)->get();
			}
		])
			->select(
				'posts.id',
				'posts.content',
				'posts.timeline_orders',
				'posts.view_count',
				'posts.images',
				'posts.user_id',
				'posts.created_at'
			)
			->orderBy('posts.timeline_orders', 'DESC')
			->orderBy('posts.id', 'DESC')
			->skip($param['offset'])->take($param['limit'])->get();

		if (empty($posts)) {
			return $this->apiResponse->dataNotfound();
		}
		foreach ($posts as $post) {
			$post->experiences = $post->author->experiences[0]->title ?? "Deo co";
			$post->skills = $post->author->skills;
			$post->total_like = count($post->likes);
			$post->total_comment = count($post->comments);

			$isLike = Post::UN_LIKE;
			if (count($post->likes) > 0) {
				foreach ($post->likes as $like) {
					if (Auth::user()->id == $like->user_id) {
						$isLike = Post::LIKE;
						break;
					}
				}
			}
			$shortContent = "";
			if (strlen($post->content) > 100) {
				$shortContent = mb_substr(
					$post->content,
					0,
					100,
					"UTF-8"
				);
			}
			$post->short_content = $shortContent;
			$post->is_like = $isLike;
			if (!is_null($post->author->avatar)) {
				$avatarTmp = $post->author->avatar;
				$post->author->_avatar = env('APP_URL')
					. '/avatars/'
					. explode('@', $post->author->email)[0]
					. '/'
					. $avatarTmp;
			} else {
				$post->author->_avatar = null;
			}
			Carbon::setLocale('app.locale');
			$created = Carbon::create($post->created_at);
			$post->since_created = $created->diffForHumans(Carbon::now());
			unset(
				$post->author->experiences,
				$post->author->skills,
				$post->likes,
				$post->comments,
				$post->created_at
			);
		}
		return $this->apiResponse->success($posts);
	}

	public function addFavorite(Request $request)
	{
		$param = $request->all();
		try {
			$now = Carbon::now();
			DB::table('favorites')->insert([
				'user_id' => Auth::user()->id,
				'post_id' => $param['post_id'],
				'created_at' => $now,
				'updated_at' => $now,
			]);
			return $this->apiResponse->success($param['post_id']);
		} catch (\Exception $e) {
			return $this->apiResponse->InternalServerError();
		}
	}

	public function removeFavorite(Request $request)
	{
		$param = $request->all();
		try {
			DB::table('favorites')
				->where('user_id', Auth::user()->id)
				->where('post_id', $param['post_id'])
				->delete();
			return $this->apiResponse->success($param['post_id']);
		} catch (\Exception $e) {
			return $this->apiResponse->InternalServerError();
		}
	}

	public function like(Request $request)
	{
		$param = $request->all();
		try {
			$now = Carbon::now();
			if ($param['action'] == 'like') {
				DB::table('likes')->insert([
					'user_id' => Auth::user()->id,
					'post_id' => $param['post_id'],
					'created_at' => $now,
					'updated_at' => $now
				]);
			} else {
				DB::table('likes')
					->where('user_id', Auth::user()->id)
					->where('post_id', $param['post_id'])
					->delete();
			}
			return $this->apiResponse->success($param['post_id']);
		} catch (\Exception $e) {
			return $this->apiResponse->InternalServerError();
		}
	}

	public function showComment(Request $request)
	{
		$param = $request->all();
		$comments = Comment::join(
			'users',
			'comments.user_id',
			'users.id'
		)->with('child', 'child.author')->select(
				'users.id as user_id',
				'users.name',
				'users.email',
				'users.avatar',
				'comments.id',
				'comments.comment',
				'comments.post_id',
				'comments.parent_id',
				'comments.created_at'
			)->where('comments.parent_id', 0)
			->where('comments.post_id', $param['post_id'])
			->orderBy('comments.id', 'DESC')
			->skip($param['offset'])->take($param['limit'])
			->get();
		if (count($comments) > 0) {
			foreach ($comments as $comment) {
				if (!is_null($comment->avatar)) {
					$avatarTmp = $comment->avatar;
					$comment->_avatar = env('APP_URL') . '/avatars/'
						. explode('@', $comment->email)[0] . '/'
						. $avatarTmp;
				} else {
					$comment->_avatar = null;
				}
				if (count($comment->child) > 0) {
					foreach ($comment->child as $cmtChild) {
						if (!is_null($cmtChild->author->avatar)) {
							$avatarTmp = $cmtChild->author->avatar;
							$cmtChild->author->_avatar = env('APP_URL') . '/avatars/'
								. explode('@', $cmtChild->author->email)[0] . '/'
								. $avatarTmp;
						} else {
							$cmtChild->author->_avatar = null;
						}
						$created_at_child_tmp = Carbon::create($cmtChild->created_at);
						$cmtChild->_created_at = $created_at_child_tmp->format('Y-m-d h:i');
					}
				}
				$created_at_tmp = Carbon::create($comment->created_at);
				$comment->_created_at = $created_at_tmp->format('Y-m-d h:i');
			}
		}
		return $this->apiResponse->success($comments);
	}

	public function postComment(Request $request)
	{
		$params = $request->all();
		$comment = Comment::create([
			'user_id' => Auth::user()->id,
			'post_id' => $params['post_id'],
			'comment' => $params['comment'],
			'parent_id' => $params['parent_id']
		]);

		$avatar = null;
		if (!is_null(Auth::user()->avatar)) {
			$avatarTmp = Auth::user()->avatar;
			$avatar = env('APP_URL') . '/avatars/'
				. explode('@', Auth::user()->email)[0] . '/'
				. $avatarTmp;
		}

		$timeNow = Carbon::now();
		$responseData = [
			'id' => $comment->id,
			'comment' => $comment->comment,
			'avatar' => $avatar,
			'name' => Auth::user()->name,
			'created_at' => $timeNow,
			'updated_at' => $timeNow,
			'parent_id' => $params['parent_id'],
			'child' => null,
			'post_id' => $params['post_id'],
			'type' => 'comment',
			'action' => 'send_comment',
			'author' => [
				'name' => Auth::user()->name,
				'_avatar' => $avatar,
			],
			'_created_at' => $timeNow,
		];
		return $this->apiResponse->success($responseData);
	}
}