<footer class="footer p-3 mt-auto bg-dark">
    <div class="navbar-nav w-100 text-light text-center">
        <div>
            <small>

            IXP Manager V<?= APPLICATION_VERSION ?>

            |

            <?php if( Auth::check() && Auth::user()->isSuperUser() ): ?>

                Generated in
                <?= sprintf( "%0.3f", microtime(true) - APPLICATION_STARTTIME ) ?>
                seconds

            <?php else: ?>


            <?php endif; ?>

            |

            EdgeIX:
            <a href="https://www.edgeix.net/">
                <i class="fa fa-globe fa-inverse mx-1"></i>
            </a>

            <a href="https://www.linkedin.com/groups/10537369/">
                <i class="fa fa-linkedin fa-inverse mx-1"></i>
            </a>

            <a href="https://www.facebook.com/edgeix.net/">
                <i class="fa fa-facebook fa-inverse mx-1" ></i>
            </a>

            </small>

        </div>
    </div>
</footer>
