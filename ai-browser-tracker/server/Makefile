.PHONY: install-deps build-neug install test

install-deps:
	pip install -r requirements.txt

build-neug:
	git clone https://github.com/alibaba/neug.git /tmp/neug
	cd /tmp/neug/tools/python_bind && \
		pip install -r requirements.txt && \
		python3 setup.py build_ext && \
		python3 setup.py bdist_wheel
	pip install /tmp/neug/tools/python_bind/dist/*.whl
	rm -rf /tmp/neug

install: install-deps build-neug

test:
	pytest tests/ -v
