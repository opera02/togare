const fs = require('fs-extra');
const exec = require('child_process').exec;
const path = require('path');

const isObject = function (item) {
    return (item && typeof item === 'object' && !Array.isArray(item));
};

const mergeDeep = function (target, ...sources) {
    if (!sources.length) {
        return target;
    }

    const source = sources.shift();

    if (isObject(target) && isObject(source)) {
        for (const key in source) {
            if (isObject(source[key])) {
                if (!target[key]) {
                    Object.assign(target, {[key]: {}});
                }

                mergeDeep(target[key], source[key]);
            } else {
                Object.assign(target, {[key]: source[key]});
            }
        }
    }

    return mergeDeep(target, ...sources);
};

exports.loadConfig = () => {
    const configDefault = require('./config-default.json');
    let config;

    if (fs.existsSync('./config.json')) {
        config = {};
        mergeDeep(config, configDefault, require('./config.json'));
    } else {
        config = configDefault;
    }

    return config;
}

const execute = (command, callback) => {
    exec(command, (error, stdout, stderr) => {
        callback(stdout);
    });
};

exports.execute = execute;

const deleteDirRecursively = path => {
    if (fs.existsSync(path) && fs.lstatSync(path).isDirectory()) {
        fs.readdirSync(path).forEach(file => {
            const curPath = path + "/" + file;

            if (fs.lstatSync(curPath).isDirectory()) {
                deleteDirRecursively(curPath);

                return;
            }

            fs.unlinkSync(curPath);
        });

        fs.rmdirSync(path);

        return;
    }

    if (fs.existsSync(path) && fs.lstatSync(path).isFile()) {
        fs.unlinkSync(path);
    }
};

exports.deleteDirRecursively = deleteDirRecursively;

exports.getProcessParam = name => {
    let value = null;

    process.argv.forEach(item => {
        if (item.indexOf('--'+name+'=') === 0) {
            value = item.split('=')[1];
        }
    });

    return value;
}

exports.camelCaseToHyphen = (string => string.replace( /([a-z])([A-Z])/g, '$1-$2' ).toLowerCase());

exports.hasProcessParam = param => {
    for (let i in process.argv) {
        if (process.argv[i] === '--' + param) {
            return true;
        }
    }

    return false;
}
